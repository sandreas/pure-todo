<?php
/**
 * Todo
 * - Enum for HttpMethods
 * - simple logging
 * - Error handling
 * - order update requires update on all items ordered AFTER the updated one
 */

/*
 * Font generation:
 * - https://sonneiltech.com/2021/02/how-to-create-your-own-custom-icon-font/
 *
 * - icomoon app (https://icomoon.io/app/#/select)
 * - select icons to use
 * - generate font (bottom right)
 * - download font (bottom right)
 * - copy css contents into php file
 * - remove all src() from @font-face
 * - generate base64 from ttf font (pilabor.com/dev)
 * - paste base64 `src: url(data:font/ttf;base64,AAEAAAALAI...AAAAAA) format('truetype');`
 * - use icon: `<i class="icon-drag_indicator"></i>`
 */


ini_set("display_errors", "on");
error_reporting(E_ALL);


/**** START CONFIG ****/
const DATA_PATH = __DIR__ . DIRECTORY_SEPARATOR . "data/";
const TOKEN_SECRET = "08154711";
const REPLACE_CHARS_IN_FILENAMES = [
    "/" => "|",
    "\\" => "|"
];

/**** END CONFIG ****/


const LISTS_PRIVATE_PATH = DATA_PATH . "lists/private/";
const LISTS_SHARED_PATH = DATA_PATH . "lists/shared/";
const AUTH_PATH = __DIR__ . "/users/";

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
    case NoFile;
    case FileNotReadable;
    case UnknownError;
}

class Jwt
{
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
        $signature = hash_hmac('SHA256', $base64_url_header . "." . $base64_url_payload, $this->secret, true);
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
        $signature = hash_hmac("SHA256", "$headers_encoded.$payload_encoded", $this->secret, true);
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

    public function __construct(array $properties)
    {
        $this->exp = (int)($properties["exp"] ?? 0);
        $this->sub = $properties["sub"] ?? "";
        $this->iss = $properties["iss"] ?? "";
        $this->name = $properties["name"] ?? "";
        $this->admin = trim($properties["admin"] ?? "") === "1";
    }

    public function jsonSerialize(): array
    {
        // the order of these properties is important for existing tokens
        // if the order is changed, every token has to be refreshed
        return [
            "iss" => $this->iss,
            "sub" => $this->sub,
            "name" => $this->name,
            "admin" => $this->admin,
            "exp" => $this->exp
        ];
    }
}

interface IdentifierInterface
{

}

class BasicIdentifier implements IdentifierInterface
{
    public string $id = "";

    public function __construct(string...$primaryKeyFields)
    {
        if (isset($primaryKeyFields[0])) {
            $this->id = $primaryKeyFields[0];
        }
    }
}


class ItemIdentifier extends BasicIdentifier
{
    public string $listId;

    public function __construct(string...$primaryKeyFields)
    {
        if (isset($primaryKeyFields[0])) {
            $this->listId = $primaryKeyFields[0];
        }
        parent::__construct($primaryKeyFields[1] ?? "");

    }
}

class User
{
    public string $id = "";
    public string $username = "";
    public string $name = "";

    public bool $isAdmin = false;

    public function __construct(string $id, string $username, string $name = "", bool $isAdmin = false)
    {
        $this->id = $id;
        $this->username = $username;
        $this->name = $name;
        $this->isAdmin = $isAdmin;
    }
}

class TodoList
{
    public string $id = "";

    public string $name = "";
    public bool $shared = false;

    public function __construct(string $id, string $name, bool $shared)
    {
        $this->id = $id;
        $this->name = $name;
        $this->shared = $shared;
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
    public string $id = "";
    public string $title = "";
    public int $order = 0;
    public bool $finished = false;

    public BetterDateTime $created;
    public BetterDateTime $modified;

    public function __construct($id, $title = "", $order = 0, $finished = false)
    {
        $this->id = $id;
        $this->title = $title;
        $this->order = $order;
        $this->finished = $finished;
        $this->created = new BetterDateTime();
        $this->modified = new BetterDateTime();
    }

    public static function fromJson($decoded, $fallbackId = ""): TodoItem
    {
        $id = ($decoded["id"] ?? "") == "" ? $fallbackId : $decoded["id"];
        $todoItem = new self($id, $decoded["title"] ?? "", $decoded["order"] ?? 0, $decoded["finished"] ?? false);
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

    public function buildModelFromArray(array $properties, ?IdentifierInterface $id): mixed;

    public function isAuthorizationRequired(): bool;

    public function isAdminRequired(): bool;

    public function index(?IdentifierInterface $id): array;

    public function read(IdentifierInterface $id): mixed;

    public function create($item, ?IdentifierInterface $id = null): bool;

    public function update(IdentifierInterface $id, $item): bool;

    public function delete(IdentifierInterface $id): bool;

    public function getMessage(): ?Message;
}

class StatusRepository implements RepositoryInterface
{
    private AuthenticationHandler $auth;

    public function __construct(AuthenticationHandler $auth)
    {
        $this->auth = $auth;
    }

    public function buildIdentifier(string ...$primaryKeyFields): IdentifierInterface
    {
        return new BasicIdentifier();
    }

    public function buildModelFromArray(array $properties, ?IdentifierInterface $id): mixed
    {
        return null;
    }

    public function isAuthorizationRequired(): bool
    {
        return false;
    }

    public function isAdminRequired(): bool
    {
        return false;
    }

    public function index(?IdentifierInterface $id): array
    {
        $status = $this->auth->getStatus();
        $user = $this->auth->getUser();
        return [
            "authenticated" => $status === JwtStatus::Ok,
            "jwtStatus" => $status->name,
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

    public function delete(IdentifierInterface $id): bool
    {
        return false;
    }

    public function getMessage(): ?Message
    {
        return null;
    }
}

class ListRepository implements RepositoryInterface
{
    const PRIVATE_DIR = "private";
    const SHARED_DIR = "shared";
    // private string $path;

    private string $privatePath;
    private string $sharedPath;
    private string $username;
    private array $lists = [];

    public function __construct(string $path, string $username)
    {
        $this->username = $username;
        $this->privatePath = $path . static::PRIVATE_DIR . "/" . normalizeId($username) . "/";
        $this->sharedPath = $path . static::SHARED_DIR . "/";
        $this->loadUserLists();
    }

    private function loadUserLists(): void
    {
        $privateLists = $this->username === "" ? [] : iterator_to_array($this->walkPath($this->privatePath, function ($name) {
            return new TodoList($this->buildListId($name, false), $name, false);
        }));

        $sharedLists = iterator_to_array($this->walkPath($this->sharedPath, function ($name) {
            return new TodoList($this->buildListId($name, true), $name, true);
        }));


        $this->lists = array_merge($privateLists, $sharedLists);
    }

    /**
     * @return Generator|TodoList[]
     */
    private function walkPath($path, callable $createList): array|Generator
    {
        if (!file_exists($path) && !mkdir($path, 0755, true)) {
            return [];
        }

        $iterator = new FilesystemIterator($path, FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $value */
        foreach ($iterator as $key => $value) {
            if ($value->isDir()) {
                yield $createList($key);
            }
        }
    }

    private function deleteRecursive($dir): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo);
            } else {
                unlink($fileInfo);
            }
        }
        rmdir($dir);
    }


    private function buildListId(string $name, bool $shared): string
    {
        return ($shared ? "1" : "0") . "-" . normalizeId($name);
    }

    public function buildPath(string $listId): string
    {
        // list id format: <flags>-<normalizedDirName>
        $parts = explode("-", $listId);
        $flags = array_shift($parts);
        $id = normalizeId(implode("-", $parts));
        if ($flags === "1") {
            return $this->sharedPath . "/" . $id . "/";
        }
        return $this->privatePath . $id . "/";
    }

    private function cast(IdentifierInterface $id): BasicIdentifier
    {
        return ($id instanceof BasicIdentifier) ? $id : new BasicIdentifier();
    }

    public function buildIdentifier(string...$primaryKeyFields): IdentifierInterface
    {
        return new BasicIdentifier(...$primaryKeyFields);
    }


    /**
     * @return TodoList[]
     */
    public function index(?IdentifierInterface $id): array
    {
        return $this->lists;
    }

    public function read(IdentifierInterface $id): ?TodoList
    {
        $casted = $this->cast($id);
        foreach ($this->lists as $list) {
            if ($list->id === $casted->id) {
                return $list;
            }
        }
        return null;
    }

    /**
     * @param TodoList $item
     */
    public function create($item, ?IdentifierInterface $id = null): bool
    {
        $item->id = $this->buildListId($item->name, $item->shared);
        $path = $this->buildPath($item->id);
        if (file_exists($path)) {
            return false;
        }
        if (!mkdir($path, 0755, true)) {
            return false;
        }
        return true;
    }


    function delete(IdentifierInterface $id): bool
    {
        $casted = $this->cast($id);
        $path = $this->buildPath($casted->id);
        if (file_exists($path)) {
            $this->deleteRecursive($path);
            return true;
        }
        return false;
    }

    /**
     * @param IdentifierInterface $id
     * @param TodoList $item
     * @return bool
     */
    public function update(IdentifierInterface $id, $item): bool
    {
        // list to update not found
        $itemToUpdate = $this->read($id);
        if ($itemToUpdate === null) {
            return false;
        }

        // list cannot be moved (already exists)
        $itemToCreate = $this->read(new BasicIdentifier($item->id));
        if ($itemToCreate !== null && $itemToCreate->id !== $itemToUpdate->id) {
            return false;
        }

        // list could not be saved
        if (!$this->create($item)) {
            return false;
        }
        return $this->delete(new BasicIdentifier($itemToUpdate->id));
    }

    public function getMessage(): ?Message
    {
        return null;
    }

    public function buildModelFromArray(array $properties, ?IdentifierInterface $id): mixed
    {
        $model = new TodoList("", "", false);
        if ($id !== null && $id->id !== "") {
            $model = $this->read($id);
        }

        $model->name = $properties["name"] ?? $model->name;
        $model->shared = (bool)($properties["shared"] ?? $model->shared);
        return $model;
    }

    public function isAuthorizationRequired(): bool
    {
        return true;
    }

    public function isAdminRequired(): bool
    {
        return false;
    }
}


class ItemRepository implements RepositoryInterface
{
    private ListRepository $lists;

    public function __construct(ListRepository $lists)
    {
        $this->lists = $lists;
    }


    private function cast(IdentifierInterface $id): ItemIdentifier
    {
        return ($id instanceof ItemIdentifier) ? $id : new ItemIdentifier();
    }

    public function buildIdentifier(string...$primaryKeyFields): IdentifierInterface
    {
        return new ItemIdentifier(...$primaryKeyFields);
    }

    function index(?IdentifierInterface $id): array
    {
        if ($id === null) {
            return [];
        }
        $casted = $this->cast($id);
        $list = $this->lists->read(new BasicIdentifier($casted->listId));

        if ($list === null) {
            return [];
        }
        $path = $this->lists->buildPath($list->id);

        return iterator_to_array($this->walkPath($path));
    }

    /**
     * @param string $path
     * @return Generator|TodoItem[]
     */
    private function walkPath(string $path): array|Generator
    {
        $iterator = new FilesystemIterator($path, FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $todo = $this->loadFile($file);
            if ($todo !== null) {
                yield $todo;
            }
        }
    }

    function read(IdentifierInterface $id): ?TodoItem
    {
        $casted = $this->cast($id);
        $list = $this->lists->read(new BasicIdentifier($casted->listId));
        if ($list === null) {
            return null;
        }

        $path = $this->buildItemPath($list->id, $casted->id);
        return $this->loadFile(new SplFileInfo($path));
    }

    private function buildItemPath($listId, $itemId): string
    {
        $id = normalizeId($itemId);
        $path = $this->lists->buildPath($listId);
        $filename = $id . ".json";
        return $path . $filename;
    }

    private function loadFile(SplFileInfo $file): ?TodoItem
    {
        if (!$file->isFile()) {
            return null;
        }
        try {
            $decoded = json_decode(file_get_contents($file), true);
            return TodoItem::fromJson($decoded, $file->getBasename(".json"));
        } catch (Throwable $t) {
            // ignore
        }
        return null;
    }

    /**
     * @param TodoItem $item
     * @param IdentifierInterface|null $id
     * @return bool
     */
    public function create($item, ?IdentifierInterface $id = null): bool
    {
        $casted = $this->cast($id);
        $itemId = $this->generateItemId($casted->listId, $item->id);
        $item->id = $itemId;
        $item->created = new BetterDateTime();
        $item->modified = new BetterDateTime();

        $path = $this->buildItemPath($casted->listId, $item->id);

        return file_put_contents($path, json_encode($item)) !== false;
    }

    private function generateItemId($listId, $title): ?string
    {
        $generatedId = normalizeId(slugify($title));
        $newId = $generatedId;
        $i = 1;
        while ($this->read(new ItemIdentifier($listId, $newId)) !== null) {
            $newId = $generatedId . "_" . $i;
            if ($i++ > 100) {
                return null;
            }
        }
        return $newId;
    }


    function delete(IdentifierInterface $id): bool
    {
        // string $listId, string $itemId
        $item = $this->read($id);
        if ($item === null) {
            return false;
        }
        $casted = $this->cast($id);

        $path = $this->buildItemPath($casted->listId, $casted->id);
        if (file_exists($path)) {
            return unlink($path);

        }
        return false;
    }

    /**
     * @param IdentifierInterface $id
     * @param TodoItem $item
     * @return bool
     */
    public function update(IdentifierInterface $id, $item): bool
    {
        // item to update not found
        $itemToUpdate = $this->read($id);
        if ($itemToUpdate === null) {
            return false;
        }
        $cast = $this->cast($id);
        // item cannot be moved (already exists)
        // todo: Moving items to another list?
        $itemToCreate = $this->read(new ItemIdentifier($cast->listId, $item->id));

        // todo: maybe we should use the IdentifierInterface here to compare listId and id
        if ($itemToCreate !== null && $itemToCreate->id !== $itemToUpdate->id) {
            return false;
        }
        $itemToCreate->id = $item->id;
        $itemToCreate->title = $item->title;
        $itemToCreate->order = $item->order;
        $itemToCreate->finished = $item->finished;
        $itemToCreate->created = $item->created;
        $itemToCreate->modified = new BetterDateTime();

        // todo: maybe provide a restore option
        $this->delete($id);

        // item could not be saved
        if (!$this->create($itemToCreate, $id)) {
            return false;
        }

        // transfer all changed properties to item object
        foreach ($itemToCreate as $key => $value) {
            $item->$key = $value;
        }
        // persist item (false on fail)
        return true;
    }

    public function getMessage(): ?Message
    {
        return null;
    }

    public function buildModelFromArray(array $properties, ?IdentifierInterface $id): mixed
    {
        $model = new TodoItem("", "", 0, false);
        if ($id !== null && $id->id !== "") {
            $model = $this->read($id);
        }

        if ($model !== null) {
            $model->title = $properties["title"] ?? $model->title;
            $model->order = (int)($properties["order"] ?? $model->order);
            $model->finished = (bool)($properties["finished"] ?? $model->finished);
        }
        return $model;
    }

    public function isAuthorizationRequired(): bool
    {
        return true;
    }

    public function isAdminRequired(): bool
    {
        return false;
    }
}


class AuthenticationHandler
{
    private string $tokenAsString = "";
    private ?Jwt $token;
    private string $usersPath;

    public function __construct(string $usersPath, array $requestHeaders, string $jwtSecret)
    {
        $this->usersPath = rtrim($usersPath, "/") . "/";
        $this->tokenAsString = $this->extractBearerToken($requestHeaders);

        $this->token = Jwt::decode($this->tokenAsString, $jwtSecret);
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

        $storedJwtPath = $this->usersPath . $sub . ".jwt";
        if (!file_exists($storedJwtPath)) {
            return JwtStatus::NoFile;
        }
        $fileContents = file_get_contents($storedJwtPath);
        if ($fileContents === false) {
            return JwtStatus::FileNotReadable;
        }
        if (strlen($fileContents) > 0 && $fileContents === trim($this->tokenAsString)) {
            return JwtStatus::Ok;
        };
        return JwtStatus::UnknownError;
    }

    public function getUser(): ?User
    {
        if ($this->token === null || $this->token->isExpired() || !$this->token->isSignatureValid()) {
            return null;
        }
        return new User($this->tokenAsString,
            $this->token->payload->sub,
            $this->token->payload->name,
            $this->token->payload->admin);
    }

    public function hasUsers()
    {
        $iterator = new FilesystemIterator($this->usersPath, FilesystemIterator::KEY_AS_FILENAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $value */
        foreach ($iterator as $key => $value) {
            if ($value->isDir()) {
                continue;
            }
            if (str_ends_with($key, ".jwt")) {
                return true;
            }
        }

        return false;
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
        if (!$this->isMethodAllowed($method)) {
            $this->sendResponse(HttpStatusCode::MethodNotAllowed);
            return true;
        }

        $routeParts = $this->parseRoute($_SERVER["REQUEST_URI"]);
        $entity = $routeParts[0] ?? "";
        $repository = $this->map[$entity] ?? null;
        if (count($routeParts) === 0 || $repository === null) {
            return false;
        }


        $user = $this->auth->getUser();
        if ($repository->isAuthorizationRequired() && $user === null) {
            $this->sendJsonErrorResponse(HttpStatusCode::Forbidden, "This request requires authorization");
            return true;
        }
        if ($repository->isAdminRequired() && ($user === null || !$user->isAdmin)) {
            $this->sendJsonErrorResponse(HttpStatusCode::Forbidden, "This request requires admin permissions");
            return true;
        }


        array_shift($routeParts);


        $identifier = $repository->buildIdentifier(...$routeParts);
        if ($method === "GET") {
            if ($identifier->id === "") {
                $this->sendJsonResponse(HttpStatusCode::Ok, $repository->index($identifier));
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
            if ($repository->delete($identifier)) {
                $this->sendResponse(HttpStatusCode::Deleted);
            } else {
                $this->sendJsonErrorResponse(HttpStatusCode::NotFound, sprintf("Could not delete entity: %s", $repository->getMessage()));
            }
            return true;
        }

        $jsonInput = $this->readJsonInput();

        $model = $repository->buildModelFromArray($jsonInput, $identifier);
        if ($method === "POST") {
            if ($repository->create($model, $identifier)) {
                $this->sendJsonResponse(HttpStatusCode::Created, $model);
            } else {
                $this->sendJsonErrorResponse(HttpStatusCode::UnprocessableEntity, sprintf("Could not create entity: %s", $repository->getMessage()));
            }
            return true;
        }
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
            $decoded = json_decode(file_get_contents("php://input"), true);
            if (isset($decoded["id"])) {
                $decoded["id"] = normalizeId($decoded["id"]);
            }
            return $decoded;
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

    private function parseRoute($requestUri): array
    {
        $urlParts = explode("?", $requestUri);

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


function slugify($text, string $divider = '-'): string
{
    // replace non letter or digits by divider
    $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

    // transliterate
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

    // remove unwanted characters
    $text = preg_replace('~[^-\w]+~', '', $text);

    // trim
    $text = trim($text, $divider);

    // remove duplicate divider
    $text = preg_replace('~-+~', $divider, $text);

    // lowercase
    return strtolower($text);
}


function normalizeId($id): string
{
    return strtr($id, REPLACE_CHARS_IN_FILENAMES);
}


function dump(mixed...$variables)
{
    echo "<pre>";
    foreach ($variables as $variable) {
        var_export($variable);
    }
    echo PHP_EOL . "==========================================" . PHP_EOL;
    echo "</pre>";
    exit;
}


$authHandler = new AuthenticationHandler(DATA_PATH . "users", getallheaders(), TOKEN_SECRET);


$statusRepository = new StatusRepository($authHandler);


$listRepository = new ListRepository(DATA_PATH, $authHandler->getUser()?->username ?? "");
$itemsRepository = new ItemRepository($listRepository);


$router = new Router($authHandler);
$router
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
    <!--<meta name="viewport" content="width=device-width, initial-scale=1.0">-->
    <title>pure todo</title>
    <style>

        html {
            box-sizing: border-box;
            font-size: 16px;
        }

        *, *:before, *:after {
            box-sizing: inherit;
        }

        body, h1, h2, h3, h4, h5, h6, p, ol, ul {
            margin: 0;
            padding: 0;
            font-weight: normal;
        }

        ol, ul {
            list-style: none;
        }

        img {
            max-width: 100%;
            height: auto;
        }

        body {
            background: rgb(31, 31, 31);
            color: rgb(227, 227, 227);
        }

        @font-face {
            font-family: 'icomoon';
            src: url(data:font/ttf;base64,AAEAAAALAIAAAwAwT1MvMg8SBccAAAC8AAAAYGNtYXAXVtKKAAABHAAAAFRnYXNwAAAAEAAAAXAAAAAIZ2x5ZvkDknYAAAF4AAAB9GhlYWQltFbLAAADbAAAADZoaGVhB0IDyQAAA6QAAAAkaG10eBYAAAAAAAPIAAAAIGxvY2EBbgC2AAAD6AAAABJtYXhwAA8AYgAAA/wAAAAgbmFtZZlKCfsAAAQcAAABhnBvc3QAAwAAAAAFpAAAACAAAwOaAZAABQAAApkCzAAAAI8CmQLMAAAB6wAzAQkAAAAAAAAAAAAAAAAAAAABEAAAAAAAAAAAAAAAAAAAAABAAADpAwPA/8AAQAPAAEAAAAABAAAAAAAAAAAAAAAgAAAAAAADAAAAAwAAABwAAQADAAAAHAADAAEAAAAcAAQAOAAAAAoACAACAAIAAQAg6QP//f//AAAAAAAg6QD//f//AAH/4xcEAAMAAQAAAAAAAAAAAAAAAQAB//8ADwABAAD/wAAAA8AAAgAANzkBAAAAAAEAAP/AAAADwAACAAA3OQEAAAAAAQAA/8AAAAPAAAIAADc5AQAAAAABAAD/wAOAA8AABQAAJQEXASc3AYABxDz+AO48+QHEPP4A7jwAAAAAAQAA/8ADKgPAAAsAAAEHFwcnByc3JzcXNwMq7u487u487u487u4Cme7uPO7uPO7uPO7uAAIAAP/AA4ADwAALABAAAAEHJzc2MzIfARYVFAkBFwEjA3ROoE4MEhIMZAz9AAHYoP4ooAJ/TqBODAxkDBIS/kAB2KD+KAAAAAYAAP/AAtYDwAAPAB8ALwA/AE8AXwAAATIXFhUUBwYjIicmNTQ3NhMyFxYVFAcGIyInJjU0NzY3IicmNTQ3NjMyFxYVFAcGJTIXFhUUBwYjIicmNTQ3NhMyFxYVFAcGIyInJjU0NzYTFAcGIyInJjU0NzYzMhcWAoAiGhoaGiIiGhoaGiIiGhoaGiIiGhoaGiIiGhoaGiIiGhoaGv7eIhoaGhoiIhoaGhoiIhoaGhoiIhoaGhp4GhoiIhoaGhoiIhoaAQEaGiIiGhoaGiIiGhoBABoaIiIaGhoaIiIaGlQaGiIiGhoaGiIiGhqsGhoiIhoaGhoiIhoa/wAaGiIiGhoaGiIiGhr+qiIaGhoaIiIaGhoaAAEAAAAAAABD3HJxXw889QALBAAAAAAA4YsJJwAAAADhiwknAAD/wAOAA8AAAAAIAAIAAAAAAAAAAQAAA8D/wAAABAAAAAAAA4AAAQAAAAAAAAAAAAAAAAAAAAgEAAAAAAAAAAAAAAACAAAABAAAAAQAAAAEAAAABAAAAAAAAAAACgAUAB4AMgBMAHAA+gAAAAEAAAAIAGAABgAAAAAAAgAAAAAAAAAAAAAAAAAAAAAAAAAOAK4AAQAAAAAAAQAHAAAAAQAAAAAAAgAHAGAAAQAAAAAAAwAHADYAAQAAAAAABAAHAHUAAQAAAAAABQALABUAAQAAAAAABgAHAEsAAQAAAAAACgAaAIoAAwABBAkAAQAOAAcAAwABBAkAAgAOAGcAAwABBAkAAwAOAD0AAwABBAkABAAOAHwAAwABBAkABQAWACAAAwABBAkABgAOAFIAAwABBAkACgA0AKRpY29tb29uAGkAYwBvAG0AbwBvAG5WZXJzaW9uIDEuMABWAGUAcgBzAGkAbwBuACAAMQAuADBpY29tb29uAGkAYwBvAG0AbwBvAG5pY29tb29uAGkAYwBvAG0AbwBvAG5SZWd1bGFyAFIAZQBnAHUAbABhAHJpY29tb29uAGkAYwBvAG0AbwBvAG5Gb250IGdlbmVyYXRlZCBieSBJY29Nb29uLgBGAG8AbgB0ACAAZwBlAG4AZQByAGEAdABlAGQAIABiAHkAIABJAGMAbwBNAG8AbwBuAC4AAAADAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA) format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: block;
        }

        [class^="icon-"], [class*=" icon-"] {
            /* use !important to prevent issues with browser extensions that change fonts */
            font-family: 'icomoon' !important;
            speak: never;
            font-style: normal;
            font-weight: normal;
            font-variant: normal;
            text-transform: none;
            line-height: 1;

            /* Better Font Rendering =========== */
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .icon-check:before {
            content: "\e900";
        }

        .icon-clear:before {
            content: "\e901";
        }

        .icon-create:before {
            content: "\e902";
        }

        .icon-drag:before {
            content: "\e903";
        }

        .modal {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
        }

        .center {
            display: grid;
            place-items: center;
        }

        .loading {
            display: inline-block;
            width: 50px;
            height: 50px;
            border: 3px solid rgba(255, 255, 255, .2);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            -webkit-animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to {
                -webkit-transform: rotate(360deg);
            }
        }

        @-webkit-keyframes spin {
            to {
                -webkit-transform: rotate(360deg);
            }
        }

        /*
        .item-finished {
            text-decoration: line-through;
        }
         */
    </style>

    <script>
        Storage.prototype.set = function (key, value) {
            localStorage.setItem(key, JSON.stringify(value));
        }
        Storage.prototype.get = function (key) {
            try {
                return JSON.parse(localStorage.getItem(key) || "null");
            } catch (e) {
                console.error(e);
            }
            return null;
        }

        window.settings = {
            bearerToken: null,
            selectedListId: null
        };

        window.lists = [];
        window.items = [];

        document.addEventListener("DOMContentLoaded", function (/* e */) {
            loadSettings();
            initPureTodo("");
        });

        function loadSettings() {
            window.settings = localStorage.get("settings") || window.settings;
        }

        function saveSettings() {
            localStorage.set("settings", window.settings);
        }

        function getDefaultHeaders() {
            var headers = {};

            if (window.settings.bearerToken) {
                headers["Authorization"] = 'Bearer ' + window.settings.bearerToken;
            }
            return headers;
        }


        function get(url) {
            return fetchJson(url, 'GET');
        }

        function patch(url, model) {
            return fetchJson(url, 'PATCH', model);
        }

        function fetchJson(url, method, body) {
            return fetch(url, {
                headers: getDefaultHeaders(),
                method: method,
                body: body === null ? null : JSON.stringify(body)
            }).then(resp => resp.json());
        }

        function initPureTodo() {
            var messageOnError = settings.bearerToken ? "Invalid bearer token specified" : "";

            get('status').then(json => {
                if (json["authenticated"]) {
                    renderApp();
                } else {
                    renderLoginForm(messageOnError);
                }
                changeLoadingLayer(false);
            }).catch(function () {
                renderLoginForm("error: could not fetch status page");
            });
        }

        function loadLists() {
            return get('lists').then(json => {
                lists = json;
            });
        }

        function loadItems(listId) {
            return get('items/' + listId).then(json => {
                items = json.sort(compareItem);
            });
        }

        function compareItem(a, b) {
            var x = a.order - b.order;
            // console.log(a.title, a.order, '<=>', b.title, b.order)
            if (x !== 0) {
                return x;
            }

            return new Date(b.modified).valueOf() - new Date(a.modified).valueOf();
        }

        function compare(a, b) {
            if (a.last_nom < b.last_nom) {
                return -1;
            }
            if (a.last_nom > b.last_nom) {
                return 1;
            }
            return 0;
        }

        function changeLoadingLayer(show) {
            document.getElementById('loading-layer').style.display = show ? 'normal' : 'none';
        }

        function renderApp() {
            replaceIdContent('main', html('div', {class: "main-container"},
                html('div', {id: 'lists'}),
                html('div', {id: 'items'})
            ));
            loadLists().then(renderListSelection).then(onChangeList);

        }

        function onChangeList() {
            settings.selectedListId = $('#lists-selection').value || settings.selectedListId;
            saveSettings();
            updateSelectedList(settings.selectedListId);
        }

        function updateSelectedList(selectedListId) {
            loadItems(selectedListId).then(renderItemsTable);
        }

        function renderListSelection() {
            var privateOptGroup = html('optgroup', {label: "Private"});
            var sharedOptGroup = html("optgroup", {label: "Shared"});
            var select = html('select', {id: 'lists-selection'}, privateOptGroup, sharedOptGroup);
            select.addEventListener('change', onChangeList);

            lists.forEach(function (l) {
                var option = html('option', {value: l.id}, text(l.name));
                if (l.id === settings.selectedListId) {
                    option.setAttribute("selected", "selected");
                }
                if (l.shared) {
                    sharedOptGroup.appendChild(option);

                } else {
                    privateOptGroup.appendChild(option);
                }
            });
            replaceIdContent('lists', select);
        }

        function renderItemsTable() {

            var open = html('table', {id: 'items-open'}, html('tbody'));
            var finished = html('table', {id: 'items-finished'}, html('tbody'));

            items.forEach(function (i) {
                var tdTitle = html('td', {class: "title"}, text(i.title));
                tdTitle.addEventListener('click', function () {
                    toggleItemFinished(i);
                });
                if (i.finished) {
                    finished.firstChild.appendChild(
                        html('tr', null,
                            html('td', {class: "drag"}),
                            tdTitle,
                            html('td', {class: "edit"})
                        )
                    )
                } else {
                    var td = html('td', {
                        class: "drag",
                        draggable: true,
                        "ondragstart": onDragStart
                    }, html("i", {class: 'icon-drag'}));

                    td.addEventListener('dragstart', onDragStart);
                    td.addEventListener('drop', function (e) {
                        onDrop(e, i);
                    });

                    var tr = html('tr', {class: "items-open-tr"},
                        td,
                        tdTitle,
                        html('td', {class: "edit"}, html("i", {class: 'icon-create'}))
                    );
                    tr.addEventListener('dragover', onDragOver);

                    open.firstChild.appendChild(tr);
                }
            });

            function onDragStart(e) {
                // console.log("dragstart", e);
                window.dragStartRow = e.target.closest("tr");
            }

            function onDrop(e, item) {
                var droppedTr = e.target.closest('tr');
                var droppedTbody = e.target.closest('tbody');
                var allTrs = Array.from(droppedTbody.querySelectorAll('tr'));
                var newIndex = allTrs.indexOf(droppedTr);
                console.log(item, "newIndex", newIndex)
                patch("items/" + settings.selectedListId + "/" + item.id, {
                    order: newIndex
                });
            }

            function onDragOver(e) {
                if (!window.dragStartRow) {
                    return;
                }

                e.preventDefault();

                var dragOverTr = e.target.closest('tr');
                var tbody = e.target.closest("tbody");
                var rows = Array.from(tbody.querySelectorAll('tr'));

                // console.log(e.target.closest("table"));

                if (rows.indexOf(dragOverTr) > rows.indexOf(window.dragStartRow)) {
                    dragOverTr.after(window.dragStartRow);
                } else {
                    dragOverTr.before(window.dragStartRow);
                }
                /*
                let children = Array.from(e.target.parentNode.parentNode.children);
                if(children.indexOf(e.target.parentNode)>children.indexOf(row))
                    e.target.parentNode.after(row);
                else
                    e.target.parentNode.before(row);

                 */
            }

            var itemsContainer = html('div', null, open, finished);
            replaceIdContent('items', itemsContainer);
        }

        function toggleItemFinished(item) {
            patch('items/' + settings.selectedListId + "/" + item.id, {
                finished: !item.finished
            }).then(function () {
                item.finished = !item.finished;
                renderItemsTable();
            });
        }

        function renderLoginForm(errorMessage) {
            var loginForm = html("form", {id: "login", class: "login-form center"},
                html("h1", null, text("Authentication")),
                html("p", {class: "error-message"}, text(errorMessage)),
                html("div", null,
                    html('p', {class: "login-form-desc"}, text("you need a valid token to authenticate.")),
                    html("label", {for: "token"}, text("Token")),
                    html("input", {id: "token", "type": "password"})
                ),
                html('button', {class: "login-form-btn"}, text("login"))
            );
            loginForm.onsubmit = submitLoginForm;
            replaceIdContent('main', loginForm);
        }


        function submitLoginForm() {
            settings.bearerToken = document.getElementById('token').value;
            saveSettings();
            initPureTodo();
            return false;
        }


        function replaceIdContent(id, child) {
            replaceContent(document.getElementById(id), child);
        }

        function replaceContent(el, child) {
            el.innerHTML = '';
            el.appendChild(child);
        }


        function html(tag, attributes) {
            var el = document.createElement(tag);

            if (attributes !== null) {
                for (var key in attributes) {
                    el.setAttribute(key, attributes[key]);
                }
            }

            for (var i = 2; i < arguments.length; i++) {
                if (arguments[i] !== null) {
                    el.appendChild(arguments[i]);
                }
            }
            return el;
        }

        function text(text) {
            return document.createTextNode(text);
        }

        function $(selector) {
            return document.querySelector(selector);
        }

    </script>

</head>
<body>
<div id="loading-layer" class="center modal">
    <div class="loading"></div>
</div>

<main id="main">

</main>
</body>
</html>
