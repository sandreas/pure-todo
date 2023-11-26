<?php
/**
 * Todo
 * - private vs shared lists (extra folder for shared ones)
 * - remove order from TodoItem in favor of /sort and /sort/:listID/
 * - Authentication
 * - Enum for HttpMethods
 * - simple logging
 * - Error handling
 * - order update requires update on all items ordered AFTER the updated one
 */

ini_set("display_errors", "on");
error_reporting(E_STRICT);

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
const AUTH_PATH = __DIR__ . "/auth/";

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

    public function __construct(string $id, string $username, string $name = "")
    {
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

class TodoItem
{
    public string $id = "";
    public string $title = "";
    public int $order = 0;
    public bool $finished = false;

    public function __construct($id, $title = "", $order = 0, $finished = false)
    {
        $this->id = $id;
        $this->title = $title;
        $this->order = $order;
        $this->finished = $finished;
    }

    public static function fromJson($decoded, $fallbackId = ""): TodoItem
    {
        $id = ($decoded["id"] ?? "") == "" ? $fallbackId : $decoded["id"];
        return new self($id, $decoded["title"] ?? "", $decoded["order"] ?? 0, $decoded["finished"] ?? false);
    }
}

class Message
{
    public string $title;

    public function __construct(string $title)
    {
        $this->title = $title;
    }
}


function pathCombineDir(string ...$parts)
{
    return pathCombine(...$parts) . DIRECTORY_SEPARATOR;
}

function pathCombine(string...$parts)
{
    $normalizedParts = [];
    if (count($parts) > 0) {
        $normalizedParts[0] = rtrim($parts[0], DIRECTORY_SEPARATOR . "/");
    }
    for ($i = 1; $i < count($parts); $i++) {
        $normalizedParts[$i] = trim($parts[$i], DIRECTORY_SEPARATOR . "/");
    }

    return implode(DIRECTORY_SEPARATOR, $normalizedParts);
}

interface RepositoryInterface
{
    public function index(?IdentifierInterface $id): array;

    public function read(IdentifierInterface $id): mixed;

    public function create($item, ?IdentifierInterface $id=null): bool;

    public function update(IdentifierInterface $id, $item): bool;

    public function delete(IdentifierInterface $id): bool;

    public function getMessage(): ?Message;
}

class ListRepository implements RepositoryInterface
{

    const PRIVATE_DIR = "private";
    const SHARED_DIR = "shared";
    private string $path;

    private string $privatePath;
    private string $sharedPath;
    private User $user;
    private array $lists = [];

    public function __construct(string $path, User $user)
    {
        $this->path = $path;
        $this->privatePath = $path . static::PRIVATE_DIR . "/";
        $this->sharedPath = $path . static::SHARED_DIR . "/";
        $this->user = $user;
    }

    private function loadUserLists(): void
    {
        $privateLists = iterator_to_array($this->walkPath($this->privatePath, function ($name) {
            return new TodoList($this->buildListId($name, false), $name, false);
        }));
        $sharedLists = iterator_to_array($this->walkPath($this->sharedPath, function ($name) {
            return new TodoList($this->buildListId($name, true), $name, true);
        }));

        $this->lists = $privateLists + $sharedLists;
    }

    /**
     * @return Generator|TodoList[]
     */
    private function walkPath($path, callable $createList): array|Generator
    {
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
        $id = normalizeId($name);
        return $shared ? static::SHARED_DIR . "/" . $id : $this->privatePath . "/" . $this->user->id . "/" . $id;
    }

    public function buildPath(string $listId): string
    {
        return $this->path . "/" . $listId . "/";
    }

    private function cast(IdentifierInterface $id): BasicIdentifier
    {
        return ($id instanceof BasicIdentifier) ? $id : new BasicIdentifier();
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
    public function create($item, ?IdentifierInterface $id=null): bool
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
        return $path . "/" . $filename;
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
    public function create($item, ?IdentifierInterface $id=null): bool
    {
        $casted = $this->cast($id);
        $itemId = $this->generateItemId($casted->listId, $item->id);
        $item->id = $itemId;

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

    public function update(IdentifierInterface $id, $item): bool
    {
        // item to update not found
        $itemToUpdate = $this->read($id);
        if ($itemToUpdate === null) {
            return false;
        }

        // item cannot be moved (already exists)
        $itemToCreate = $this->read(new BasicIdentifier($item->id));
        // todo: maybe we should use the IdentifierInterface here to compare listId and id
        if ($itemToCreate !== null && $itemToCreate->id !== $itemToUpdate->id) {
            return false;
        }

        // item could not be saved
        if (!$this->create($item, $id)) {
            return false;
        }
        $this->delete($id);

        // persist item (false on fail)
        return true;
    }

    public function getMessage(): ?Message
    {
        return null;
    }
}


class Router {
    public function __construct($routes = [])
    {
        $routes = [
            "lists" => new ListsRepository(),
            "items" => new ItemsRepository()
        ];
    }
}


function buildItemId($listId, $itemId): array
{
    $id = normalizeId($itemId);
    return [$id, LISTS_PRIVATE_PATH . DIRECTORY_SEPARATOR . $listId . DIRECTORY_SEPARATOR . $id . ".json"];
}

function normalizeId($id): string
{
    return strtr($id, REPLACE_CHARS_IN_FILENAMES);
}



function parseJwtStatus(string $jwt, array $jwtArray): JwtStatus
{
    if ($jwt === "") {
        return JwtStatus::Missing;
    }

    $valid = $jwtArray["valid"] ?? false;
    if (!$valid) {
        return JwtStatus::Invalid;
    }

    $expired = $jwtArray["expired"] ?? true;
    if ($expired) {
        return JwtStatus::Expired;
    }
    $sub = $jwtArray["payload"]["sub"] ?? "";
    if ($sub === "") {
        return JwtStatus::NoSub;
    }
    $storedJwtPath = AUTH_PATH . "/" . $sub . ".jwt";
    if (!file_exists($storedJwtPath)) {
        return JwtStatus::NoFile;
    }
    $fileContents = file_get_contents($storedJwtPath);
    if ($fileContents === false) {
        return JwtStatus::FileNotReadable;
    }
    if (strlen($fileContents) > 0 && $fileContents === trim($jwt)) {
        return JwtStatus::Ok;
    };
    return JwtStatus::UnknownError;
}

function handleApiRequest(): bool
{
    $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
    if (!isMethodAllowed($method)) {
        sendResponse(HttpStatusCode::MethodNotAllowed);
        return true;
    }
    [$entityType, $listId, $itemId] = parseEntity($_SERVER["REQUEST_URI"]);

    if ($entityType === "") {
        return false;
    }

    // handle non-authenticated requests
    if ($entityType === "jwt") {
        handleJwtRequest($method);
        return true;
    }


    $jwt = extractBearerToken();
    $jwtArray = jwtDecode($jwt, TOKEN_SECRET);
    $jwtStatus = parseJwtStatus($jwt, $jwtArray);

    if ($entityType === "status") {
        handleStatusRequest($jwtStatus);
        return true;
    }

    if ($jwtStatus !== JwtStatus::Ok) {
        sendJsonErrorResponse(HttpStatusCode::Forbidden, "bearer token unusable: " . $jwtStatus->name);
        return true;
    }

    switch ($entityType) {
        case "lists":
            handleListsRequest($method, $listId);
            return true;
        case "items":
            handleItemsRequest($method, $listId, $itemId);
            return true;
    }
    return false;
}

function handleStatusRequest(JwtStatus $status): void
{
    sendJsonResponse(HttpStatusCode::Ok, [
        "authenticated" => $status === JwtStatus::Ok,
        "jwtStatus" => $status->name
    ]);
}

function handleJwtRequest(string $method): void
{
    if ($method === "POST") {
        $jsonInput = readJsonInput();
    } else {
        $jsonInput = $_GET;
    }

    /*
    iss (issuer): Issuer of the JWT
    sub (subject): Subject of the JWT (the user)
    aud (audience): Recipient for which the JWT is intended (typically server url - e.g. https://example.com)
    exp (expiration time): Time after which the JWT expires
    nbf (not before time): Time before which the JWT must not be accepted for processing
    iat (issued at time): Time at which the JWT was issued; can be used to determine age of the JWT
    jti (JWT ID): Unique identifier; can be used to prevent the JWT from being replayed (allows a token to be used only once)
     */
    if (!isset($jsonInput["sub"]) || (string)$jsonInput["sub"] === "") {
        sendJsonErrorResponse(HttpStatusCode::BadRequest, "field 'sub' is required");
        return;
    }


    $sub = (string)$jsonInput["sub"];
    $exp = (int)($jsonInput["exp"] ?? (new DateTime("+10 years"))->getTimestamp());
    $name = (string)($jsonInput["name"] ?? "");
    $secret = (string)($jsonInput["secret"] ?? TOKEN_SECRET);
    $headers = [
        'alg' => 'HS256',
        'typ' => 'JWT'
    ];
    $payload = [
        'iss' => 'pure-todo',
        'sub' => $sub,
        'name' => $name,
        'admin' => false,
        'exp' => $exp
    ];
    $jwt = jwtGenerate($headers, $payload, "SHA256", $secret);
    sendJsonResponse(HttpStatusCode::Created, ["jwt" => $jwt]);
}

function extractBearerToken(): string
{
    $headers = getallheaders();
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

function handleItemsRequest(string $method, string $listId, string $itemId): void
{
    $listId = normalizeId($listId);
    $itemId = normalizeId($itemId);

    if ($listId === "") {
        sendJsonErrorResponse(HttpStatusCode::BadRequest, "list id is required to get items");
        return;
    }
    $lists = iterator_to_array(listIndex());
    $requestedList = findListById($lists, $listId);
    if ($requestedList === null) {
        sendJsonErrorResponse(HttpStatusCode::NotFound, "list not found");
    }
    // list request
    if ($method === "GET" && $itemId === "") {
        $items = iterator_to_array(itemIndex($listId));
        sendJsonResponse(HttpStatusCode::Ok, $items);
        return;
    }

    // single item request
    $requestedItem = findItemById($listId, $itemId);
    if ($method === "GET") {
        if ($requestedItem !== null) {
            sendJsonResponse(HttpStatusCode::Ok, $requestedItem);
        } else {
            sendResponse(HttpStatusCode::NotFound);
        }
        return;
    }

    if ($method === "DELETE") {
        if ($requestedItem !== null) {
            itemDelete($listId, $itemId);
            sendResponse(HttpStatusCode::Deleted);
            return;
        }
        sendResponse(HttpStatusCode::NotFound);
        return;
    }

    $jsonInput = readJsonInput();

    if ($method === "POST") {
        $item = TodoItem::fromJson($jsonInput);
        if ($item->id === "") {
            $item->id = generateItemId($listId, $item->title);
        }
        $createdItem = itemCreate($listId, $item);
        if ($createdItem === null) {
            sendJsonErrorResponse(HttpStatusCode::InternalServerError, "could not create item");
            return;
        }

        sendJsonResponse(HttpStatusCode::Created, $createdItem);
        return;
    }


    $requestedItem->id = $jsonInput["id"] ?? $requestedItem->id;
    $requestedItem->title = $jsonInput["title"] ?? $requestedItem->title;
    $requestedItem->finished = $jsonInput["finished"] ?? $requestedItem->finished;
    $requestedItem->order = $jsonInput["order"] ?? $requestedItem->order;

    $newListId = $jsonInput["listId"] ?? $listId;
    itemUpdate($listId, $newListId, $itemId, $requestedItem);
    sendJsonResponse(HttpStatusCode::Ok, $requestedItem);
}

/*
function generateItemId($listId, $title): ?string {
    $generatedId = normalizeId(slugify($title));
    $newId = $generatedId;
    $i = 1;
    while(findItemById($listId, $newId) !== null) {
        $newId = $generatedId."_".$i;
        if($i++ > 100) {
            return null;
        }
    }
    return $newId;
}
*/


function parseEntity($requestUri): array
{
    $urlParts = explode("?", $requestUri);

    $parts = array_filter(explode("/", trim($urlParts[0], "/")), function ($part) {
        return trim($part) !== "";
    });

    return [$parts[0] ?? "", $parts[1] ?? "", $parts[2] ?? ""];
}


function sendJsonErrorResponse(HttpStatusCode $statusCode, string $errorMessage): void
{
    sendJsonResponse($statusCode, ["errors" => [new Message($errorMessage)]]);
}

function sendJsonResponse(HttpStatusCode $statusCode, mixed $content): void
{
    header('Content-Type: application/json');
    sendResponse($statusCode, json_encode($content));
}

function sendResponse(HttpStatusCode $statusCode, string $content = ""): void
{
    http_response_code($statusCode->value);
    echo $content;
}


function handleListsRequest(string $method, string $listId): void
{
    $listId = normalizeId($listId);

    $lists = iterator_to_array(listIndex());
    $requestedList = findListById($lists, $listId);

    if ($method === "GET") {
        if ($listId === "") {
            sendJsonResponse(HttpStatusCode::Ok, $lists);
            return;
        }
        if ($requestedList !== null) {
            sendJsonResponse(HttpStatusCode::Ok, $requestedList);
        } else {
            sendResponse(HttpStatusCode::NotFound);
        }
        return;
    }

    if ($method === "DELETE") {
        if ($requestedList !== null) {
            listDelete($listId);
            sendResponse(HttpStatusCode::Deleted);
            return;
        }
        sendResponse(HttpStatusCode::NotFound);
        return;
    }

    $jsonInput = readJsonInput();
    $newId = $jsonInput["id"] ?? "";

    if ($method === "POST") {
        if ($newId === "" || findListById($lists, $newId) !== null) {
            sendJsonErrorResponse(HttpStatusCode::UnprocessableEntity, $newId === "" ?
                "id is required to create a list" :
                "list already exists");
            return;
        }
        $list = listCreate($newId);
        if ($list === null) {
            sendResponse(HttpStatusCode::UnprocessableEntity);
            return;
        }
        sendJsonResponse(HttpStatusCode::Created, $list);
        return;
    }
    $updatedList = listUpdate($listId, $newId);
    sendJsonResponse(HttpStatusCode::Ok, $updatedList);
}

function readJsonInput(): array
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

function findListById($lists, $listId): ?TodoList
{
    if ($listId === "") {
        return null;
    }
    foreach ($lists as $list) {
        if ($list->id === $listId) {
            return $list;
        }
    }
    return null;
}


function isMethodAllowed($method): bool
{
    return $method === "GET" || $method === "POST" || $method === "PATCH" || $method === "PUT" || $method === "DELETE";
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


if (handleApiRequest()) {
    exit;
}


/**
 * @param array $headers
 * @param array $payload
 * @param string $algo
 * @param string $secret
 * @return string
 */
function jwtGenerate(array $headers, array $payload, string $algo = "SHA256", string $secret = ''): string
{
    $headers_encoded = urlBase64Encode(json_encode($headers));
    $payload_encoded = urlBase64Encode(json_encode($payload));
    $signature = hash_hmac($algo, "$headers_encoded.$payload_encoded", $secret, true);
    $signature_encoded = urlBase64Encode($signature);
    return $headers_encoded . "." . $payload_encoded . "." . $signature_encoded;
}

function urlBase64Encode($str): string
{
    return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
}

function jwtDecode($jwt, $secret): array
{
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) < 3) {
        return [];
    }

    $header = base64_decode($tokenParts[0]);
    $payload = base64_decode($tokenParts[1]);
    $signatureProvided = $tokenParts[2];

    $headerDecoded = json_decode($header, true);
    $payloadDecoded = json_decode($payload, true);
    $expiration = (int)($payloadDecoded["exp"] ?? 0);

    $isTokenExpired = ($expiration - time()) < 0;

    // build a signature based on the header and payload using the secret
    $base64_url_header = urlBase64Encode($header);
    $base64_url_payload = urlBase64Encode($payload);
    $signature = hash_hmac('SHA256', $base64_url_header . "." . $base64_url_payload, $secret, true);
    $base64_url_signature = urlBase64Encode($signature);

    // verify it matches the signature provided in the jwt
    $isSignatureValid = ($base64_url_signature === $signatureProvided);

    return [
        "header" => $headerDecoded,
        "payload" => $payloadDecoded,
        "signature" => $signature,
        "valid" => $isSignatureValid,
        "expired" => $isTokenExpired
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            background: lightblue;
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

        .item-finished {
            text-decoration: line-through;
        }
    </style>

    <script>
        var lists = [];
        var items = [];

        document.addEventListener("DOMContentLoaded", function (event) {
            initPureTodo("");
        });

        function getDefaultHeaders() {
            var token = localStorage.getItem("authorization");
            var headers = {};

            if (token) {
                headers["Authorization"] = 'Bearer ' + token;
            }
            return headers;
        }


        function get(url) {
            return fetch(url, {
                headers: getDefaultHeaders(),
                method: 'GET'
            }).then(resp => resp.json());
        }

        function initPureTodo(errorMessage) {
            get('status').then(json => {
                if (json.authenticated) {
                    renderApp();
                } else {
                    renderLoginForm(errorMessage);
                }
                changeLoadingLayer(false);
            }).catch(function () {
                alert("fetch error");
            });
        }

        function loadLists() {
            return get('lists').then(json => {
                lists = json;
            });
        }

        function loadItems(listId) {
            return get('items/' + listId).then(json => {
                items = json;
            });
        }

        function changeLoadingLayer(show) {
            document.getElementById('loading-layer').style.display = show ? 'normal' : 'none';
        }

        function renderApp() {
            replaceIdContent('main', html('div', {class: "main-container"},
                html('div', {id: 'lists'}),
                html('div', {id: 'items'})
            ));
            loadLists().then(renderLists).then(() => {
                if (lists.length > 0) {
                    loadItems(lists[1].id).then(renderItems);
                }
            });

        }

        function renderLists() {
            var ul = html('ul', {id: 'list-selection'});
            for (var key in lists) {
                ul.appendChild(html('li', null, text(lists[key].id)));
            }
            replaceIdContent('lists', ul);
        }

        function renderItems() {

            var open = html('ul', {id: 'items-open'});
            items.filter(i => !i.finished).forEach(i => open.appendChild(
                html('li', null, text(i.title))
            ));

            var finished = html('ul', {id: 'items-finished'});
            items.filter(i => i.finished).forEach(i => finished.appendChild(
                html('li', {class: 'item-finished'}, text(i.title))
            ));

            var itemsContainer = html('div', null, open, finished);

            replaceIdContent('items', itemsContainer);
        }

        function renderLoginForm(errorMessage) {
            var loginForm = html("form", {id: "login", class: "login-form center"},
                html("h1", null, text("Authentication")),
                html("p", {class: "error-message"}, text(errorMessage)),
                html("div", null,
                    html('p', {class: "login-form-desc"}, text("you need a token to authenticate.")),
                    html("label", {for: "token"}, text("Token")),
                    html("input", {id: "token", "type": "password"})
                ),
                html('button', {class: "login-form-btn"}, text("login"))
            );
            loginForm.onsubmit = submitLoginForm;
            replaceIdContent('main', loginForm);
        }

        function replaceIdContent(id, child) {
            replaceContent(document.getElementById(id), child);
        }

        function replaceContent(el, child) {
            el.innerHTML = '';
            el.appendChild(child);
        }

        function submitLoginForm() {
            var token = document.getElementById('token').value;
            localStorage.setItem('authorization', token);
            initPureTodo("Invalid token");
            return false;
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
