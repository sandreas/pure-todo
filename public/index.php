<?php
/**** START CONFIG ****/
// $_ENV["DEBUG"] = true;
$_ENV["TOKEN_SECRET"] ??= "<use-a-strong-token-secret-here>";
$_ENV["DBFILE"] ??= __DIR__ . DIRECTORY_SEPARATOR . "/../data/todo.db";
$_ENV["LOGFILE"] ??= __DIR__ . DIRECTORY_SEPARATOR . "/../data/perfmon.log";
/**** END CONFIG ****/


// requested file
$requestedFile = realpath("public".$_SERVER["REQUEST_URI"]);

if ($requestedFile && str_starts_with($requestedFile,__DIR__) && str_ends_with($requestedFile, "sw.js") && file_exists($requestedFile)) {

    header("Content-Type: application/javascript; charset=UTF-8");
       readfile($requestedFile);
       exit;
}

$_ENV["REQUEST_ID"] = sprintf("%08x", abs(crc32($_SERVER['REMOTE_ADDR'] . $_SERVER['REQUEST_TIME'] . $_SERVER['REMOTE_PORT'])));

if ($_ENV["DEBUG"] ?? false) {
    $_ENV["PERFMON"] ??= "";
    error_reporting(E_ALL);
    ini_set('display_errors', 'on');
}


function perfmon($marker): void
{
    if (!isset($_ENV["PERFMON"])) {
        return;
    }
    static $start = 0;
    $end = microtime(true);
    $duration = $start === 0 ? 0 : round(($end - $start) * 1000);
    $start = $end;
    $_ENV["PERFMON"] .= str_pad($duration, 5, " ", STR_PAD_LEFT) . "ms " . $_ENV["REQUEST_ID"] . " " . $marker . PHP_EOL;
}

function perfmon_flush(): void
{
    if (isset($_ENV["LOGFILE"], $_ENV["PERFMON"])) {
        file_put_contents($_ENV["LOGFILE"], $_ENV["PERFMON"], FILE_APPEND);
    }
}

register_shutdown_function(function () {
    perfmon('shutdown');
    perfmon_flush();
});

function dump(mixed...$variables): void
{
    echo "<pre>";
    foreach ($variables as $variable) {
        var_export($variable);
    }
    echo PHP_EOL . "==========================================" . PHP_EOL;
    echo "</pre>";
    exit;
}


enum HttpStatusCode: int
{
    case Ok = 200;
    case Created = 201;
    case Deleted = 204;
    case BadRequest = 400;
    case Forbidden = 403;
    case NotFound = 404;
    case MethodNotAllowed = 405;
    case UnprocessableEntity = 422;
    case InternalServerError = 500;
}

/*
enum HttpMethod: string {
    case GET = "GET";
    case POST = "POST";
    case PUT = "PUT";
    case PATCH = "PATCH";
    case DELETE = "DELETE";
}
*/

enum JwtStatus
{
    case Ok;
    case Invalid;
    case Expired;
    case Missing;
    case NoSub;
    case NotFound;

}

class Jwt
{
    const HEADER_ALGO_NAME = "HS256";
    const HASH_ALGO_NAME = "SHA256";
    public array $headers = [];
    public string $signature = "";

    public JwtPayload $payload;
    public string $secret = "";

    public function __construct(array $headers, JwtPayload $payload, string $signature, string $secret)
    {
        $this->headers = $headers;
        $this->payload = $payload;
        $this->signature = $signature;
        $this->secret = $secret;
    }

    public static function generate($payload, $secret): string
    {
        $signing_key = $secret;
        $header = [
            "alg" => static::HEADER_ALGO_NAME,
            "typ" => "JWT"
        ];

        $header = static::urlBase64Encode(json_encode($header));
        $payload = static::urlBase64Encode(json_encode($payload));
        $signature = static::urlBase64Encode(hash_hmac(static::HASH_ALGO_NAME, "$header.$payload", $signing_key, true));
        return $header . "." . $payload . "." . $signature;
    }


    public function isExpired(): bool
    {
        return (($this->payload->exp ?? 0) - time()) < 0;
    }

    public function isSignatureValid(): bool
    {
        return $this->buildSignature() === $this->signature;
    }

    public function buildSignature(): string
    {
        $header = json_encode($this->headers);

        $payloadAsArray = $this->payload;

        $payload = json_encode($payloadAsArray);
        $base64_url_header = static::urlBase64Encode($header);
        $base64_url_payload = static::urlBase64Encode($payload);
        $signature = hash_hmac(static::HASH_ALGO_NAME, $base64_url_header . "." . $base64_url_payload, $this->secret, true);
        return static::urlBase64Encode($signature);
    }

    private static function urlBase64Encode($str): string
    {
        return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
    }

    public function __toString(): string
    {
        $headers_encoded = static::urlBase64Encode(json_encode($this->headers));
        $payload_encoded = static::urlBase64Encode(json_encode($this->payload));
        $signature = hash_hmac(static::HASH_ALGO_NAME, "$headers_encoded.$payload_encoded", $this->secret, true);
        $signature_encoded = static::urlBase64Encode($signature);
        return $headers_encoded . "." . $payload_encoded . "." . $signature_encoded;
    }

    public static function decode(string $jwt, string $secret): ?Jwt
    {
        $tokenParts = explode('.', $jwt);
        if (count($tokenParts) < 3) {
            return null;
        }

        $header = base64_decode($tokenParts[0]);
        $payload = base64_decode($tokenParts[1]);
        $signatureProvided = $tokenParts[2];
        $headerDecoded = json_decode($header, true);
        $payloadDecoded = json_decode($payload, true);

        return new Jwt($headerDecoded, new JwtPayload($payloadDecoded), $signatureProvided, $secret);
    }
}

class JwtPayload implements JsonSerializable
{
    public string $iss = "";
    public string $sub = "";
    public string $name = "";
    public bool $admin = false;
    public int $exp = 0;

    public function __construct(array $properties = [])
    {
        $this->exp = (int)($properties["exp"] ?? 0);
        $this->sub = $properties["sub"] ?? "";
        $this->iss = $properties["iss"] ?? "";
        $this->name = $properties["name"] ?? "";
        $this->admin = (bool)trim($properties["admin"] ?? "");
    }

    public static function build(string $username, string $name, bool $isAdmin, int $expires = null): JwtPayload
    {
        return new static([
            "exp" => $expires ?? (new DateTime("+10 years"))->getTimestamp(),
            "sub" => $username,
            "iss" => "pure-todo",
            "name" => $name,
            "admin" => $isAdmin
        ]);
    }

    public function jsonSerialize(): array
    {
        // the priority of these properties is important for existing tokens
        // if the priority is changed, every token has to be refreshed
        return [
            "iss" => $this->iss,
            "sub" => $this->sub,
            "name" => $this->name,
            "admin" => $this->admin,
            "exp" => $this->exp
        ];
    }
}

/*
$payload = new JwtPayload();
$payload->exp = (new DateTime('+10 years'))->getTimestamp();
$payload->sub = "admin";
$payload->name = "Andreas";
$payload->iss = "pure-todo";
$payload->admin = true;

echo Jwt::generate($payload, TOKEN_SECRET);
exit;
*/

class TodoPdo extends PDO
{

    private string $file;

    public function __construct(string $file, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $this->file = $file;
        parent::__construct("sqlite:".$file, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function createSchemaIfNotExists():void
    {
        if(file_exists($this->file) && filesize($this->file) > 0) {
            return;
        }

        $sqls[] = "
    CREATE TABLE IF NOT EXISTS todo_users ( 
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username INTEGER NOT NULL UNIQUE,
        name TEXT NOT NULL,
        admin INTEGER DEFAULT 0 NOT NULL,
        disabled INTEGER DEFAULT 0 NOT NULL,
        token TEXT NOT NULL,
        created TEXT NOT NULL,
        modified TEXT NOT NULL,
        create_user_id INTEGER,
        modify_user_id INTEGER,
        FOREIGN KEY (create_user_id) REFERENCES todo_users(id),
        FOREIGN KEY (modify_user_id) REFERENCES todo_users(id)
      );";
        $sqls[] = "
    CREATE TABLE IF NOT EXISTS todo_lists( 
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name INTEGER NOT NULL,
        shared INTEGER NOT NULL,
        priority INTEGER NOT NULL,
        created TEXT NOT NULL,
        modified TEXT NOT NULL,
        prioritized TEXT NOT NULL,
        create_user_id INTEGER NOT NULL,
        modify_user_id INTEGER NOT NULL,
        FOREIGN KEY (create_user_id) REFERENCES todo_users(id),
        FOREIGN KEY (modify_user_id) REFERENCES todo_users(id)
    );";
        $sqls[] = "
    CREATE TABLE IF NOT EXISTS todo_items( 
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        list_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        priority INTEGER NOT NULL,
        finished INTEGER NOT NULL,
        created TEXT NOT NULL,
        modified TEXT NOT NULL,
        prioritized TEXT NOT NULL,
        create_user_id INTEGER NOT NULL,
        modify_user_id INTEGER NOT NULL,
        FOREIGN KEY (list_id) REFERENCES todo_lists(id) ON DELETE CASCADE,
        FOREIGN KEY (create_user_id) REFERENCES todo_users(id),
        FOREIGN KEY (modify_user_id) REFERENCES todo_users(id)
    );";
        $success = false;
        try {
            foreach ($sqls as $sql) {
                $this->preparedExecute($sql);
            }
            $success = true;
        } finally {
            if(!$success && file_exists($this->file)) {
                unlink($this->file);
            }
        }
    }

    public function preparedDebug($sql, $parameters = []): string
    {
        foreach($parameters as $key => $value) {
            $sql = str_replace(":".$key, "'".$value."'", $sql);
        }
        return $sql;
    }

    /**
     * @throws Exception
     */
    public function preparedExecute(string $sql, array $params = null, ?PDOStatement $sth = null): PDOStatement
    {
        $sth ??= $this->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        if ($sth === false) {
            throw new Exception($this->errorInfoToMessage($this->errorInfo()));
        }
        if (!$sth->execute($params)) {
            throw new Exception($this->errorInfoToMessage($sth->errorInfo()));
        }
        return $sth;
    }

    private function errorInfoToMessage(array $info): string
    {
        $messageParts = [];
        if (isset($info[2])) {
            $messageParts[] = $info[2];
        }
        if (isset($info[1])) {
            $messageParts[] = "Code: " . $info[1];
        }

        if (isset($info[0])) {
            $messageParts[] = "SQLSTATE: " . $info[0];
        }

        return implode(", ", $messageParts);
    }
}

$db = new TodoPdo($_ENV["DBFILE"]);

try {
    $db->createSchemaIfNotExists();
} catch (Exception $e) {
    die($e);
}



interface IdentifierInterface
{

}

class BasicIdentifier implements IdentifierInterface
{
    public int $id = 0;

    public function __construct(string...$primaryKeyFields)
    {
        if (isset($primaryKeyFields[0])) {
            $this->id = (int)$primaryKeyFields[0];
        }
    }
}

class User
{
    public int $id = 0;
    public string $username = "";
    public string $name = "";
    public bool $admin = false;
    public bool $disabled = false;
    public string $token = "";

    public bool $refreshToken = false;

    public BetterDateTime $created;
    public BetterDateTime $modified;

    public function __construct(int $id, string $username, string $name = "", bool $admin = false, bool $refreshToken = false)
    {
        // TODO: Generate Token!
        $this->id = $id;
        $this->username = $username;
        $this->name = $name;
        $this->admin = $admin;
        $this->refreshToken = $refreshToken;
        $this->created = new BetterDateTime();
        $this->modified = new BetterDateTime();
    }
}

class TodoList
{
    public int $id = 0;

    public string $name = "";
    public bool $shared = false;
    public int $priority = 0;


    public BetterDateTime $created;
    public BetterDateTime $modified;
    public BetterDateTime $prioritized;

    public function __construct(string $id, string $name, bool $shared)
    {
        $this->id = $id;
        $this->name = $name;
        $this->shared = $shared;
        $this->priority = 0;
        $this->created = new BetterDateTime();
        $this->modified = new BetterDateTime();
        $this->prioritized = new BetterDateTime();
    }
}

class BetterDateTime extends DateTime implements JsonSerializable
{
    public function __toString(): string
    {
        return $this->format(DATE_ATOM);
    }

    public function jsonSerialize(): mixed
    {
        return $this->__toString();
    }
}

class TodoItem
{
    public int $id = 0;
    public int $listId = 0;
    public string $title = "";
    public int $priority = 0;
    public bool $finished = false;

    public BetterDateTime $created;
    public BetterDateTime $modified;
    public BetterDateTime $prioritized;

    public bool $priorityChanged= false;

    public function __construct($id, $listId, $title = "", $priority = 0, $finished = false)
    {
        $this->id = $id;
        $this->listId = (int)$listId;
        $this->title = $title;
        $this->priority = $priority;
        $this->finished = $finished;
        $this->created = new BetterDateTime();
        $this->modified = new BetterDateTime();
        $this->prioritized = new BetterDateTime();
    }

    public static function fromJson($decoded, $fallbackId = ""): TodoItem
    {
        $id = ($decoded["id"] ?? "") == "" ? $fallbackId : $decoded["id"];
        $todoItem = new self($id, (int)$decoded["listId"], $decoded["title"] ?? "", $decoded["priority"] ?? 0, $decoded["finished"] ?? false);
        try {
            $todoItem->created = new BetterDateTime($decoded["created"] ?? "now");
            $todoItem->modified = new BetterDateTime($decoded["modified"] ?? "now");

        } catch (Exception $e) {
            $todoItem->created = new BetterDateTime();
            $todoItem->modified = new BetterDateTime();
        }

        return $todoItem;
    }

}

class Message
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    public function __toString(): string
    {
        return $this->title;
    }
}

interface RepositoryInterface
{
    public function buildIdentifier(string...$primaryKeyFields): IdentifierInterface;

    public function createModelViaArray(array $properties): mixed;
    public function updateModelViaArray(array $properties, ?IdentifierInterface $id=null): mixed;

    public function isAuthorizationRequired(): bool;

    public function adminRequired(): bool;

    public function index(?IdentifierInterface $id=null, array $criteria=[]): array;

    public function read(IdentifierInterface $id): mixed;

    public function create($item, ?IdentifierInterface $id = null): bool;

    public function update(IdentifierInterface $id, $item): bool;

    public function delete(IdentifierInterface $id, array $criteria=[]): bool;

    public function getMessage(): ?Message;
}

abstract class AbstractRepository implements RepositoryInterface
{
    public function buildIdentifier(string ...$primaryKeyFields): IdentifierInterface
    {
        return new BasicIdentifier(...$primaryKeyFields);
    }

    public function getMessage(): ?Message
    {
        return null;
    }
}

class StatusRepository extends AbstractRepository
{
    private AuthenticationHandler $auth;

    private bool $setupMode;

    public function __construct(AuthenticationHandler $auth, $setupMode)
    {
        $this->auth = $auth;
        $this->setupMode = $setupMode;
    }


    public function isAuthorizationRequired(): bool
    {
        return false;
    }

    public function adminRequired(): bool
    {
        return false;
    }

    public function index(?IdentifierInterface $id=null, array $criteria = []): array
    {
        $status = $this->auth->getStatus();
        $user = $this->auth->getAuthenticatedUser();
        return [
            "authenticated" => $status === JwtStatus::Ok,
            "jwtStatus" => $status->name,
            "setupMode" => $this->setupMode,
            "user" => $user
        ];
    }

    public function read(IdentifierInterface $id): mixed
    {
        return null;
    }

    public function create($item, ?IdentifierInterface $id = null): bool
    {
        return false;
    }

    public function update(IdentifierInterface $id, $item): bool
    {
        return false;
    }

    public function delete(IdentifierInterface $id, array $criteria=[]): bool
    {
        return false;
    }


    public function createModelViaArray(array $properties): mixed
    {
        return null;
    }

    public function updateModelViaArray(array $properties, ?IdentifierInterface $id = null): mixed
    {
        return null;
    }
}



class UserRepository extends AbstractRepository
{

    private TodoPdo $db;
    private ?User $authenticatedUser;
    public int $userCount = 0;
    /**
     * @var callable
     */
    private $buildToken;

    public function __construct(TodoPdo $db, callable $buildToken)
    {
        $this->db = $db;
        $this->buildToken = $buildToken;
        $this->userCount = $this->countFiltered();
    }

    public function setAuthenticatedUser(?User $authenticatedUser): void
    {
        $this->authenticatedUser = $authenticatedUser;
    }

    public function createModelViaArray(array $properties): mixed
    {
        $user = new User((int)($properties["id"] ?? 0), $properties["username"] ?? "", $properties["name"] ?? "", (bool)($properties["admin"] ?? false), (bool)($properties["refreshToken"] ?? false));

        if(isset($properties["token"])) {
            $user->token = $properties["token"];
        }
        return $user;
    }

    public function countFiltered(array $criteria = []): int
    {
        $filter = new Filter($criteria);
        $sql = "SELECT COUNT(1) as counter FROM todo_users".$filter->includeWhere();
        try {
            $st = $this->db->preparedExecute($sql,$filter->mergeParameters());
            foreach($st as $record) {
                return (int)($record["counter"] ?? 0);
            }
        } catch(Exception $e) {
            // ignore
        }

        return 0;
    }

    public function queryOne(array $criteria = []): ?User
    {
        $filter = new Filter($criteria);
        $sql = "SELECT id, username, name, admin, token, created, modified 
            FROM todo_users".$filter->includeWhere();
        $params = $filter->mergeParameters();
        try {
            $st = $this->db->preparedExecute($sql,$params);
            foreach($st as $record) {

                return $this->createModelViaArray($record);
            }

        } catch(Exception $e) {
        }

        return null;
    }

    public function updateModelViaArray(array $properties, ?IdentifierInterface $id = null): mixed
    {
        $model = $this->read($id);

        if(isset($properties["username"])) {
            $model->username = $properties["username"];
        }
        if(isset($properties["name"])) {
            $model->name = $properties["name"];
        }        
        if(isset($properties["admin"])) {
            $model->admin = (bool)$properties["admin"];
        }

        if(isset($properties["refreshToken"])) {
            $model->refreshToken = (bool)$properties["refreshToken"];
        }

        return $model;
    }

    public function isAuthorizationRequired(): bool
    {
        return $this->userCount > 0;
    }

    public function adminRequired(): bool
    {
        return $this->userCount > 0;
    }

    public function index(?IdentifierInterface $id=null, array $criteria = []): array
    {
        try {
            $sql = "SELECT id, username, name, admin, token FROM todo_users";
            $st = $this->db->preparedExecute($sql);

            $results = [];
            foreach($st as $record) {
                $results[] = $this->createModelViaArray($record);
            }
            return $results;
        }catch(Exception $e) {
            return [];
        }
    }

    public function read(IdentifierInterface $id): ?User
    {
        try {
            $sql = "SELECT id, username, name, admin, token FROM todo_users WHERE id = :id";
            $st = $this->db->preparedExecute($sql, ["id" => $id->id]);

            foreach($st as $record) {
                return $this->createModelViaArray($record);
            }
            return null;
        }catch(Exception $e) {
        }
        return null;

    }

    /**
     * @param User $item
     * @param IdentifierInterface|null $id
     * @return bool
     */
    public function create($item, ?IdentifierInterface $id = null): bool
    {
        try {
            $sql = "INSERT INTO todo_users (
                        username, name, admin, token,
                        created, 
                        modified, 
                        create_user_id, 
                        modify_user_id 
                    ) VALUES(
                         :username, 
                         :name, 
                         :admin, 
                         :token,
                         :created, 
                         :modified, 
                         :create_user_id, 
                         :modify_user_id
                    )";

            $buildToken = $this->buildToken;
            $item->token = $buildToken($item->username, $item->name, $item->admin);
            $params = [
                "username" => $item->username,
                "name" => $item->name,
                "admin" => $item->admin,
                "token" => $item->token,
                "created" => $item->created,
                "modified" => $item->modified,
                "create_user_id" => $this->authenticatedUser?->id,
                "modify_user_id" => $this->authenticatedUser?->id
            ];
            $this->db->preparedExecute($sql, $params);
            $item->id = $this->db->lastInsertId();
            return true;
        }catch(Exception $e) {
            return false;
        }
    }

    /**
     * @param IdentifierInterface $id
     * @param User $item
     * @return bool
     */
    public function update(IdentifierInterface $id, $item): bool
    {
        // only admins can update users
        if($this->authenticatedUser === null || $this->authenticatedUser->admin === false) {
            return false;
        }

        try {
            // authenticated user cannot up- or downgrade his own admin permissions
            if($this->authenticatedUser->id === $item->id  && $item->admin !== $this->authenticatedUser->admin) {
                return false;
            }

            $parameters = [
                "username" => $item->username,
                "name" => $item->name,
                "admin" => $item->admin,
                "modified" => $item->modified,
                "id" => $item->id,
                "modify_user_id" => $this->authenticatedUser?->id
            ];
            $tokenPart = "";
            if($item->refreshToken) {
                $buildToken = $this->buildToken;
                $parameters["token"] = $buildToken($item->username, $item->name, $item->admin);
                $tokenPart = "token = :token,";
                $item->token = $parameters["token"];
            }


            $sql = "UPDATE todo_users SET
                username = :username,
                name = :name,
                admin = :admin,
                ".$tokenPart."
                modified = :modified,
                modify_user_id = :modify_user_id         
            WHERE id = :id";

            $this->db->preparedExecute($sql, $parameters);
            return true;
        }catch(Exception $e) {
            return false;
        }
    }

    public function delete(IdentifierInterface $id, array $criteria=[]): bool
    {
        try {
            // users cannot delete themselves
            if($this->authenticatedUser !== null && $this->authenticatedUser->id === $id->id) {
                return false;
            }
            $sql = "DELETE FROM todo_users WHERE id = :id";
            $this->db->preparedExecute($sql, ["id" => $id->id]);
            return true;
        }catch(Exception $e) {
            return false;
        }
    }


}

class ListRepository extends AbstractRepository
{

    private int $userId;
    private TodoPdo $db;

    public function __construct(TodoPdo $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
    }

    /**
     * @return TodoList[]
     */
    public function index(?IdentifierInterface $id=null, array $criteria = []): array
    {
        try {
            $sql = "SELECT id, name, shared, created, modified FROM todo_lists WHERE create_user_id = :create_user_id OR shared = 1";
            $st = $this->db->preparedExecute($sql, [
                    "create_user_id" => $this->userId
            ]);

            $results = [];
            foreach($st as $record) {
                $results[] = $this->createModelViaArray($record);
            }
            return $results;
        }catch(Exception $e) {
            return [];
        }
    }


    public function read(IdentifierInterface $id): ?TodoList
    {
        try {
            $sql = "SELECT name, shared, created, modified FROM todo_lists WHERE id = :id AND (create_user_id = :created_user_id OR shared = 1)";
            $st = $this->db->preparedExecute($sql, ["id" => $id->id, "create_user_id" => $this->userId]);

            foreach($st as $record) {
                return $this->createModelViaArray($record);
            }
            return null;
        }catch(Exception $e) {
            return null;
        }
    }

    /**
     * @param TodoList $item
     */
    public function create($item, ?IdentifierInterface $id = null): bool
    {
        try {
            $sql = "INSERT INTO todo_lists(
                        name, 
                        shared, 
                        priority,
                        created, 
                        modified, 
                        prioritized,
                        create_user_id, 
                        modify_user_id 
                    ) VALUES(
                         :name, 
                         :shared, 
                         :priority,
                         :created, 
                         :modified,
                         :prioritized,
                         :create_user_id, 
                         :modify_user_id
                    )";
            $this->db->preparedExecute($sql, [
                     "name" => $item->name,
                     "shared" => $item->shared,
                     "priority" => $item->priority,
                     "created" => $item->created,
                     "modified" => $item->modified,
                     "prioritized" => $item->prioritized,
                     "create_user_id" => $this->userId,
                     "modify_user_id" => $this->userId
            ]);
            $item->id = $this->db->lastInsertId();
            return true;
        }catch(Exception $e) {
            return false;
        }
    }


    function delete(IdentifierInterface $id, array $criteria=[]): bool
    {
        try {
            // user can only delete lists he created
            $sql = "DELETE FROM todo_lists WHERE id = :id AND create_user_id = :create_user_id";
            $this->db->preparedExecute($sql, ["id" => $id->id, "create_user_id" => $this->userId]);
            return true;
        }catch(Exception $e) {
            return false;
        }
    }

    /**
     * @param IdentifierInterface $id
     * @param TodoList $item
     * @return bool
     */
    public function update(IdentifierInterface $id, $item): bool
    {
        try {
            // user can only modify lists he created
            $sql = "UPDATE todo_lists SET
                name = :name, 
                shared = :shared, 
                priority = :priority,
                created = :created, 
                modified = :modified, 
                prioritized = :prioritized, 
                modify_user_id = :modify_user_id             
            WHERE id = :id AND create_user_id = :create_user_id";
            $this->db->preparedExecute($sql, [
                "name" => $item->name,
                "shared" => $item->shared,
                "priority" => $item->priority,
                "modified" => $item->modified,
                "prioritized" => $item->prioritized,
                "modify_user_id" => $this->userId,
                "id" => $item->id,
                "create_user_id" => $this->userId
            ]);
            return true;
        }catch(Exception $e) {
            return false;
        }
    }

    public function isAuthorizationRequired(): bool
    {
        return true;
    }

    public function adminRequired(): bool
    {
        return false;
    }

    public function createModelViaArray(array $properties): mixed
    {
        return new TodoList((int)($properties["id"] ?? 0), $properties["name"] ?? "", (bool)($properties["shared"]??false));
    }

    public function updateModelViaArray(array $properties, ?IdentifierInterface $id = null): mixed
    {
        $model = $this->read($id);
        if(isset($properties["id"])) {
            $model->id = (int)$properties["id"];
        }
        if(isset($properties["name"])) {
            $model->name = $properties["name"];
        }
        if(isset($properties["shared"])) {
            $model->shared = $properties["shared"];
        }
        if(isset($properties["priority"])) {
            $newPrio = (int)$properties["priority"];
            if($newPrio != $model->priority) {
                $model->prioritized = new BetterDateTime();
                $model->priority = $newPrio;
            }
        }

        return $model;
    }
}

class Filter
{
    private array $keyMapping = [];
    private array $keyValuePairs = [];

    public function __construct(array $criteria = [], array $keyMapping=[])
    {
        foreach($criteria as $key => $value) {
            $this->addParameter($key, $value);
        }
        $this->keyMapping = $keyMapping;
    }

    private function mapKey($key): string
    {
           return $this->keyMapping[$key] ?? $key;
    }

    private function mapKeys($keyValuePairs): array
    {
        $mapped = [];
        foreach($keyValuePairs as $key => $value) {
            $mapped[$this->mapKey($key)] = $value;
        }
        return $mapped;
    }

    public function addParameter(string $key, mixed $value): void
    {
        $this->keyValuePairs[$key] = $value;
    }

    public function mergeParameters(array $parameters = []): array
    {

        return $this->mapKeys(array_merge($this->keyValuePairs, $parameters));
    }

    public function __toString(): string
    {
        $sql = "";
        foreach($this->keyValuePairs as $key => $value) {
            $mappedKey = $this->mapKey($key);
            if($sql !== "") {
                $sql .= " AND ";
            }
            $sql .= $mappedKey. " = :".$mappedKey;
        }
        return $sql;
    }

    public function includeWhere()
    {
        $str = trim($this);
        return $str === "" ? "" : " WHERE ".$str;
    }


}


class ItemRepository extends AbstractRepository
{
    private TodoPdo $db;
    private int $userId;

    const KEY_MAPPING = ["listId" => "list_id"];

    public function __construct(TodoPdo $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
    }


    function index(?IdentifierInterface $id=null, array $criteria = []): array
    {
        try {
            $criteria = new Filter($criteria, static::KEY_MAPPING);

            $sql = "SELECT id, list_id, title, priority, finished, created, modified           FROM todo_items
                    WHERE ".$criteria. " 
                        -- ensure that user is allowed to access these items
                        AND (create_user_id = :create_user_id OR list_id IN (SELECT list_id FROM todo_lists WHERE shared = 1))
                    ORDER BY finished, priority DESC, modified DESC";
            $fixedParams = [
                "create_user_id" => $this->userId
            ];
            $parameters = $criteria->mergeParameters($fixedParams);
            $st = $this->db->preparedExecute($sql, $parameters);
            $results = [];
            foreach($st as $record) {
                $results[] = $this->createModelViaArray($record);
            }
            return $results;
        }catch(Exception $e) {
            return [];
        }
    }

    function read(IdentifierInterface $id): ?TodoItem
    {
        try {
            $sql = "SELECT id, list_id, title, priority, finished, created, modified FROM todo_items WHERE id = :id AND (create_user_id = :create_user_id OR list_id IN (SELECT list_id FROM todo_lists WHERE shared = 1))";
            $st = $this->db->preparedExecute($sql, ["id" => $id->id, "create_user_id" => $this->userId]);

            foreach($st as $record) {
                return $this->createModelViaArray($record);
            }
            return null;
        }catch(Exception $e) {
            return null;
        }
    }

    /**
     * @param TodoItem $item
     * @param IdentifierInterface|null $id
     * @return bool
     */
    public function create($item, ?IdentifierInterface $id = null): bool
    {
        try {
            $mappedUserId = $this->mapCreateUserIdForList($item->listId);

            if($mappedUserId === 0) {
                return false;
            }



            // items will be created in the name of the list owner
            // to prevent mixed permissions - this is not ideal but should work
            $sql = "INSERT INTO todo_items(
                        list_id, title, priority, finished, created, modified, prioritized, create_user_id, modify_user_id
                    ) VALUES(
                         :list_id, 
                         :title, 
                         :priority, 
                         :finished, 
                         :created, 
                         :modified, 
                         :prioritized, 
                         :create_user_id, 
                         :modify_user_id
                    )";
            $this->db->preparedExecute($sql, [
                "list_id" => $item->listId,
                "title" => $item->title,
                "priority" => $item->priority,
                "finished" => $item->finished,
                "created" => $item->created,
                "modified" => $item->modified,
                "prioritized" => $item->prioritized,
                "create_user_id" => $mappedUserId,
                "modify_user_id" => $this->userId
            ]);
            $item->id = $this->db->lastInsertId();
            return true;
        }catch(Exception $e) {
            return false;
        }
    }


    function delete(IdentifierInterface $id, array $criteria=[]): bool
    {
        try {
            if($id->id) {
                $criteria["id"] = $id->id;
            } else if (!isset($criteria["listId"])) {
                // criteria MUST include either id or listId
                return false;
            }
            $filter = new Filter($criteria, static::KEY_MAPPING);
            // user cannot delete items created by others
            $sql = "DELETE FROM todo_items".$filter->includeWhere()." AND (create_user_id = :create_user_id OR list_id IN (SELECT list_id FROM todo_lists WHERE shared = 1))";
            $this->db->preparedExecute($sql, $filter->mergeParameters());
            return true;
        }catch(Exception $e) {
            return false;
        }
    }

    /**
     * @param IdentifierInterface $id
     * @param TodoItem $item
     * @return bool
     */
    public function update(IdentifierInterface $id, $item): bool
    {
        try {
            $priorityFixRequired = $this->isPriorityFixRequired($item);
            // problem: what if list_id has changed?
            if(($priorityFixRequired || $item->priorityChanged) && !$this->updatePriorities($id, $item)) {
                return false;
            }


            $userId = $this->mapCreateUserIdForList($item->listId);
            // no permission
            if($userId === 0) {
                return false;
            }
            $sql = "UPDATE todo_items SET
             list_id = :list_id,
             title = :title,
             priority = :priority,
             finished = :finished,
             modified = :modified,
             prioritized = :prioritized,
             modify_user_id = :modify_user_id
            WHERE 
                id = :id AND (create_user_id = :create_user_id OR list_id IN (SELECT list_id FROM todo_lists WHERE shared = 1))";
            $params = [
                "list_id" => $item->listId,
                "title" => $item->title,
                "priority" => $item->priority,
                "finished" => $item->finished,
                "modified" => $item->modified,
                "prioritized" => $item->prioritized,
                "modify_user_id" => $this->userId,
                "id" => $id->id,
                "create_user_id" => $this->userId,
            ];
            // die($this->db->preparedDebug($sql, $params));
            $this->db->preparedExecute($sql,$params);
            return true;
        }catch(Exception $e) {
            return false;
        }
    }

    public function createModelViaArray(array $properties): ?TodoItem
    {
        return new TodoItem(
                (int)($properties["id"] ?? 0),
            (int)($properties["list_id"] ?? $properties["listId"] ?? 0),
                    $properties["title"] ?? "",
            (int)($properties["priority"] ?? 0),
            (bool)($properties["finished"] ?? false)
        );
    }

    public function isAuthorizationRequired(): bool
    {
        return true;
    }

    public function adminRequired(): bool
    {
        return false;
    }

    public function updateModelViaArray(array $properties, ?IdentifierInterface $id = null): ?TodoItem
    {
        $model = $this->read($id);
        if(isset($properties["id"])) {
            $model->id = (int)$properties["id"];
        }

        if(isset($properties["listId"])) {
            $model->listId = (int)$properties["listId"];
        }

        if(isset($properties["title"])) {
            $model->title = $properties["title"];
        }
        if(isset($properties["priority"])) {
            $newPrio = (int)$properties["priority"];

            if($model->priority !== $newPrio) {
                $model->priority = $newPrio;
                $model->prioritized = new BetterDateTime();
                $model->priorityChanged = true;
            }
        }
        if(isset($properties["finished"])) {
            $model->finished = (bool)$properties["finished"];
        }
        return $model;
    }

    private function mapCreateUserIdForList(int $listId): int
    {
        $list = null;
        try {
            $sql = " SELECT create_user_id, shared FROM todo_lists WHERE id = :list_id";
            $st = $this->db->preparedExecute($sql, [
                "list_id" => $listId
            ]);
            foreach($st as $record) {
                $list = $record;
            }
        } catch(Exception) {
            return 0;
        }


        if($list === null) {
            return 0;
        }

        if($listId <= 0) {
            return 0;
        }

        $createUserId = (int)$list["create_user_id"];
        if($createUserId === $this->userId) {
            return $this->userId;
        }
        if($list["shared"]) {
            return $createUserId;
        }
        return 0;
    }

    private function updatePriorities(IdentifierInterface $id, TodoItem $item): bool
    {
        $currentOrder = [];
        try {

            $sql = "SELECT id, priority FROM todo_items WHERE id <> :id AND list_id = :list_id AND finished <> 1 ORDER BY priority DESC";
            $params = ["id" => $id->id, "list_id" => $item->listId];
            $st = $this->db->preparedExecute($sql, $params);
            foreach($st as $record) {
                $currentOrder[(int)$record["id"]] = (int)$record["priority"];
            }

        } catch(Exception) {
            return false;
        }
        $ids = array_keys($currentOrder);
        $newOrder = [];
        $count = count($currentOrder);
        for($i=$count;$i>0;$i--) {
            // skip current item prio value (updated later)
            if($i === $item->priority) {
                continue;
            }
            $id = array_shift($ids);
            if($id === null) {
                break;
            }
            $newOrder[$id] = $i;
        }

        $sql = "UPDATE todo_items SET priority = :priority WHERE id = :id";
        $sth = null;
        foreach($newOrder as $id => $priority) {
            if($currentOrder[$id] === $priority) {
                continue;
            }

            try {
                $sth = $this->db->preparedExecute($sql, [
                    "priority" => $priority,
                    "id" => $id
                ], $sth);
            } catch (Exception $e) {
                return false;
            }
        }
        return true;
    }

    private function isPriorityFixRequired(TodoItem $item): bool
    {
        $sql = "SELECT COUNT(priority) as counter FROM todo_items WHERE list_id = :list_id GROUP BY priority ORDER BY counter DESC LIMIT 1;";
        try {
            $st = $this->db->preparedExecute($sql, ["list_id" => $item->listId]);
            foreach($st as $record) {
                return (int)$record["counter"] > 1;
            }
        } catch(Exception) {
            // ignore
        }
        return true;
    }
}


class AuthenticationHandler
{
    private string $tokenAsString = "";
    private ?Jwt $token;
    private UserRepository $users;

    private ?User $authenticatedUser;

    public function __construct(UserRepository $users, array $requestHeaders, string $jwtSecret)
    {

        $this->users = $users;
        $this->tokenAsString = $this->extractBearerToken($requestHeaders);
        $this->token = Jwt::decode($this->tokenAsString, $jwtSecret);
        $this->authenticatedUser = null;
    }

    private function extractBearerToken(array $headers): string
    {
        $authorization = $headers["Authorization"] ?? "";
        if ($authorization === "") {
            return "";
        }
        $bearerPrefix = "Bearer ";

        if (!str_starts_with($authorization, $bearerPrefix)) {
            return "";
        }

        return substr($authorization, strlen($bearerPrefix));
    }

    public function getStatus(): JwtStatus
    {
        if ($this->token === null) {
            return JwtStatus::Missing;
        }

        if (!$this->token->isSignatureValid()) {
            return JwtStatus::Invalid;
        }

        if ($this->token->isExpired()) {
            return JwtStatus::Expired;
        }

        $sub = $this->token->payload->sub;

        if ($sub === "") {
            return JwtStatus::NoSub;
        }

        if(null === $this->getAuthenticatedUser()) {
            return JwtStatus::NotFound;
        }
        return JwtStatus::Ok;
    }

    public function getAuthenticatedUser(): ?User
    {
        if ($this->token === null || $this->token->isExpired() || !$this->token->isSignatureValid()) {
            return null;
        }

        if($this->authenticatedUser === null) {
            $this->authenticatedUser = $this->users->queryOne(["token" => $this->tokenAsString]);
        }

        return $this->authenticatedUser;
    }
}

class Router
{
    /** @var RepositoryInterface[] */
    private array $map = [];

    private AuthenticationHandler $auth;

    public function __construct(AuthenticationHandler $auth)
    {
        $this->auth = $auth;
    }

    public function map(string $entityType, RepositoryInterface $repository): Router
    {
        $this->map[$entityType] = $repository;
        return $this;
    }

    public function handleRequest($server): bool
    {
        $method = $server["REQUEST_METHOD"] ?? "GET";
        perfmon("router->handleRequest: ".$method);

        if (!$this->isMethodAllowed($method)) {
            $this->sendResponse(HttpStatusCode::MethodNotAllowed);
            return true;
        }

        $routeParts = $this->parseRoute($_SERVER["REQUEST_URI"], $urlParameters);

        perfmon("routeParts: ".implode(",", $routeParts)." | queryString: ".json_encode($urlParameters));
        if(!is_array($urlParameters)) {
            $urlParameters = [];
        }
        $criteria = $urlParameters["where"] ?? [];
        $entity = $routeParts[0] ?? "";
        $repository = $this->map[$entity] ?? null;
        if (count($routeParts) === 0 || $repository === null) {
            return false;
        }

        $user = $this->auth->getAuthenticatedUser();
        if ($repository->isAuthorizationRequired() && $user === null) {
            $this->sendJsonErrorResponse(HttpStatusCode::Forbidden, "This request requires authorization");
            return true;
        }
        if ($repository->adminRequired() && ($user === null || !$user->admin)) {
            $this->sendJsonErrorResponse(HttpStatusCode::Forbidden, "This request requires admin permissions");
            return true;
        }


        array_shift($routeParts);


        $identifier = $repository->buildIdentifier(...$routeParts);
        if ($method === "GET") {
            if ($identifier->id === 0) {
                $this->sendJsonResponse(HttpStatusCode::Ok, $repository->index($identifier, $criteria));
                return true;
            }

            $result = $repository->read($identifier);
            if ($result === null) {
                $this->sendJsonErrorResponse(HttpStatusCode::NotFound, sprintf("Could not get entity: %s", $repository->getMessage()));
            } else {
                $this->sendJsonResponse(HttpStatusCode::Ok, $result);
            }
            return true;
        }

        if ($method === "DELETE") {
            if ($repository->delete($identifier, $criteria)) {
                $this->sendResponse(HttpStatusCode::Deleted);
            } else {
                $this->sendJsonErrorResponse(HttpStatusCode::NotFound, sprintf("Could not delete entity: %s", $repository->getMessage()));
            }
            return true;
        }

        $jsonInput = $this->readJsonInput();
        perfmon("request data: ".json_encode($jsonInput));
        if ($method === "POST") {
            $model = $repository->createModelViaArray($jsonInput);
            if ($repository->create($model, $identifier)) {
                $this->sendJsonResponse(HttpStatusCode::Created, $model);
            } else {
                $this->sendJsonErrorResponse(HttpStatusCode::UnprocessableEntity, sprintf("Could not create entity: %s", $repository->getMessage()));
            }
            return true;
        }
        $model = $repository->updateModelViaArray($jsonInput, $identifier);
        if ($repository->update($identifier, $model)) {
            $this->sendJsonResponse(HttpStatusCode::Ok, $model);
        } else {
            $this->sendJsonErrorResponse(HttpStatusCode::UnprocessableEntity, sprintf("Could not update entity: %s", $repository->getMessage()));
        }

        return true;
    }

    private function readJsonInput(): array
    {
        try {
            return json_decode(file_get_contents("php://input"), true);
        } catch (Throwable $t) {
            // ignore
        }
        return [];
    }

    private function isMethodAllowed($method): bool
    {
        return $method === "GET" || $method === "POST" || $method === "PATCH" || $method === "PUT" || $method === "DELETE";
    }

    private function sendResponse(HttpStatusCode $statusCode, string $content = ""): void
    {
        http_response_code($statusCode->value);
        echo $content;
    }

    private function parseRoute($requestUri, &$urlParameters): array
    {

        $urlParts = explode("?", $requestUri);
        if(isset($urlParts[1])) {
            parse_str($urlParts[1], $urlParameters);
        }
        $pathParts = explode("/", trim($urlParts[0], "/"));
        return array_filter($pathParts, function ($part) {
            return trim($part) !== "";
        });
    }


    function sendJsonErrorResponse(HttpStatusCode $statusCode, string $errorMessage): void
    {
        $this->sendJsonResponse($statusCode, ["errors" => [new Message($errorMessage)]]);
    }

    function sendJsonResponse(HttpStatusCode $statusCode, mixed $content): void
    {
        header('Content-Type: application/json');
        $this->sendResponse($statusCode, json_encode($content));
    }
}

perfmon('start');

$userRepository = new UserRepository($db, function($username, $name, $admin): string {
    $payload = JwtPayload::build($username, $name, $admin);
    return Jwt::generate($payload, $_ENV["TOKEN_SECRET"]);
});
$authHandler = new AuthenticationHandler($userRepository, getallheaders(), $_ENV["TOKEN_SECRET"]);
$userRepository->setAuthenticatedUser($authHandler->getAuthenticatedUser());
$statusRepository = new StatusRepository($authHandler, $userRepository->userCount === 0);
$listRepository = new ListRepository($db, $authHandler->getAuthenticatedUser()?->id ?? 0);
$itemsRepository = new ItemRepository($db, $authHandler->getAuthenticatedUser()?->id ?? 0);


$router = new Router($authHandler);
$router
    ->map("users", $userRepository)
    ->map("status", $statusRepository)
    ->map("lists", $listRepository)
    ->map("items", $itemsRepository);


if ($router->handleRequest($_SERVER)) {
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <?php /* <meta name="viewport" content="width=device-width, initial-scale=1.0"> */ ?>
    <!--<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no"> -->
    <meta name="viewport" content="width=device-width; initial-scale=1; viewport-fit=cover">


    <title>pure todo</title>
    <link rel="manifest" href="app.webmanifest">

    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">

    <!-- possible content values: default, black or black-translucent -->
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">



    <?php /*
    <link rel="stylesheet" href="css/reset.css">
    <link rel="stylesheet" href="css/icons.css">
    <link rel="stylesheet" href="css/loading.css">
    <link rel="stylesheet" href="css/dropdown.css">
    <link rel="stylesheet" href="css/global.css">
    <script src="js/extensions.js"></script>
    <script src="js/helpers.js"></script>
    <script src="js/global.js"></script>
 */ ?>
    <style>
        <?php
        $css = glob(__DIR__."/css/*.css");
        foreach($css as $file) {
            echo file_get_contents($file).PHP_EOL;
        }
        ?>
    </style>
    <script>
        <?php
        $css = glob(__DIR__."/js/*.js");
        foreach($css as $file) {
            echo file_get_contents($file).PHP_EOL;
        }
        ?>
    </script>

    <script>
        window.settings = defaultSettings();
        window.route = null;
        window.authenticatedUser = null;
        window.lists = [];
        window.items = [];
        window.users = [];


        if ('serviceWorker' in navigator) {
            navigator.serviceWorker
                .register('pwa/sw.js')
                .then(() => { console.log('Service Worker Registered'); });
        }

        /*
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            alert("go");
        });
        */
        document.addEventListener("DOMContentLoaded", function (/* e */) {
            initApp();

            var deferredPrompt;
            var addBtn = document.querySelector('.add-button');

            window.addEventListener('beforeinstallprompt', (e) => {
                console.log("something");
                // Prevent Chrome 67 and earlier from automatically showing the prompt
                e.preventDefault();
                // Stash the event so it can be triggered later.
                deferredPrompt = e;
                // Update UI to notify the user they can add to home screen
                addBtn.style.display = 'block';

                addBtn.addEventListener('click', () => {
                    // hide our user interface that shows our A2HS button
                    addBtn.style.display = 'none';
                    // Show the prompt
                    deferredPrompt.prompt();
                    // Wait for the user to respond to the prompt
                    deferredPrompt.userChoice.then((choiceResult) => {
                        if (choiceResult.outcome === 'accepted') {
                            console.log('User accepted the A2HS prompt');
                        } else {
                            console.log('User dismissed the A2HS prompt');
                        }
                        deferredPrompt = null;
                    });
                });
            });

        });


        window.onhashchange = function () {
            initApp()
        };


    </script>

</head>
<body>


<main id="main">
    <div id="todos" class="content hidden">
        <div id="todos-header" class="route-header">
            <i><button class="add-button" style="border:0;margin:0;padding:4px;background:transparent;font-size:0.75em;cursor:pointer;">🐶</button></i>
            <div id="todos-header-lists-selection"></div>
            <a href="#!/todos/new"><i id="btn-create-item" class="icon-add_task"></i></a>
        </div>
        <div id="todos-filter">
            <span><i id="btn-add-from-new" class="icon-add_task" onclick="changeLocation('todo', 'new', null, {title:document.getElementById('todos-filter-query').value})"></i></span>
            <label for="todos-filter-query">
                <input placeholder="Filter" type="search" value="" id="todos-filter-query" oninput="renderItemTables()"/>
            </label>
            <button id="btn-clear-search" class="icon-btn" onclick="document.getElementById('todos-filter-query').value = '';renderItemTables()"><i class="icon-clear"></i></button>
        </div>

        <div id="todos-content">

        </div>
    </div>

    <div id="lists" class="content hidden">
        <div id="lists-header" class="route-header">
            <i class="icon-filter_list_alt"></i>
            <h1>Lists</h1>
            <a href="#!/lists/new"><i id="btn-create-item" class="icon-post_add"></i></a>
        </div>
        <div id="lists-content">

        </div>
    </div>
    <div id="users" class="content hidden">
        <div id="users-header" class="route-header">
            <i class="icon-filter_list_alt"></i>
            <h1>Users</h1>
            <a href="#!/users/new"><i id="btn-create-item" class="icon-person_add_alt_1"></i></a>
        </div>
        <div id="users-content">

        </div>
    </div>

    <div id="form-container" class="content hidden">

    </div>
</main>

<!--
 select => dropdown button
 https://codepen.io/raneio/pen/NbbZEM
 -->

<nav id="navigation">
    <ul id="navigation-list">
        <li id="navigation-list-todos"><a href="#/todos">
                <i class="icon-check"></i>
                <span>Todo</span></a>
        </li>
        <li id="navigation-list-lists"><a href="#!/lists">
                <i class="icon-format_list_bulleted"></i>
                <span>Lists</span></a>
        </li>
        <li id="navigation-list-users"><a href="#!/users">
                <i class="icon-person"></i>
                <span>Users</span></a>
        </li>
        <li id="navigation-list-logout">
            <a href="javascript:confirmLogout();"><i class="icon-logout"></i>
                <span>Logout</span></a>
        </li>
    </ul>
</nav>
<div id="loading-layer" class="hidden center modal">
    <div class="loading"></div>
</div>
<div class="bottom-spacer"></div>

</body>
</html>
