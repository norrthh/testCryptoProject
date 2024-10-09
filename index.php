<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'config.php';

class Database
{
    private $host;
    private $db;
    private $user;
    private $pass;
    private $connection;

    public function __construct($config)
    {
        $this->host = $config['database']['host'];
        $this->db = $config['database']['db'];
        $this->user = $config['database']['user'];
        $this->pass = $config['database']['pass'];
    }

    public function connect()
    {
        if ($this->connection == null) {
            $dsn = "pgsql:host={$this->host};port=5432;dbname={$this->db};user={$this->user};password={$this->pass}";
            try {
                $this->connection = new PDO($dsn);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Connection failed: ' . $e->getMessage()]);
                exit;
            }
        }
        return $this->connection;
    }
}

class Cryptocurrency
{
    private PDO $db;

    public function __construct($config)
    {
        $database = new Database($config);
        $this->db = $database->connect();
    }

    public function getAll(): void
    {
        $stmt = $this->db->query("SELECT * FROM cryptocurrency");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getBySymbol($symbol): void
    {
        $stmt = $this->db->prepare("SELECT * FROM cryptocurrency WHERE symbol = :symbol");
        $stmt->execute(['symbol' => $symbol]);
        $crypto = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($crypto) {
            echo json_encode($crypto);
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Cryptocurrency not found"]);
        }
    }

    public function calculateValue($symbol, $amount, $currency): void
    {
        if (!is_numeric($amount) || $amount <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid amount"]);
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM cryptocurrency WHERE symbol = :symbol");
        $stmt->execute(['symbol' => $symbol]);
        $crypto = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$crypto) {
            http_response_code(404);
            echo json_encode(["error" => "Cryptocurrency not found"]);
            return;
        }

        $total_price = $crypto['price_usd'] * $amount;

        if ($currency !== 'USD') {
            $conversion_rate = $this->getConversionRate('USD', $currency);

            if ($conversion_rate === null) {
                http_response_code(400);
                echo json_encode(["error" => "Unsupported currency"]);
                return;
            }

            $total_price *= $conversion_rate;
        }

        echo json_encode([
            "amount" => $amount,
            "symbol" => $symbol,
            "price_per_unit" => $crypto['price_usd'],
            "currency" => $currency,
            "total_price" => $total_price
        ]);
    }

    public function updatePrices(): void
    {
        $apiUrl = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum&vs_currencies=usd';

        $apiResponse = @file_get_contents($apiUrl);
        if ($apiResponse === FALSE) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to retrieve data from API"]);
            return;
        }

        $prices = json_decode($apiResponse, true);
        if (!$prices) {
            http_response_code(500);
            echo json_encode(["error" => "Invalid data from API"]);
            return;
        }

        $cryptos = [
            'bitcoin' => 'BTC',
            'ethereum' => 'ETH'
        ];

        foreach ($cryptos as $name => $symbol) {
            if (isset($prices[$name]['usd'])) {
                $stmt = $this->db->prepare("UPDATE cryptocurrency SET price_usd = :price, updated_at = NOW() WHERE symbol = :symbol");
                $stmt->execute(['price' => $prices[$name]['usd'], 'symbol' => $symbol]);
            }
        }

        echo json_encode(["message" => "Cryptocurrency prices updated successfully"]);
    }

    private function getConversionRate(string $fromCurrency, string $toCurrency)
    {
        $apiUrl = "https://api.exchangerate-api.com/v4/latest/{$fromCurrency}";
        $apiResponse = @file_get_contents($apiUrl);
        if ($apiResponse === FALSE) {
            return null;
        }

        $rates = json_decode($apiResponse, true);
        if (isset($rates['rates'][$toCurrency])) {
            return $rates['rates'][$toCurrency];
        }
        return null;
    }
}

class Router
{
    private string $method;
    private array $uri;
    private Cryptocurrency $cryptoCurrency;

    public function __construct(array $config)
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->uri = explode('/', trim($_SERVER['REQUEST_URI'], '/'));
        $this->cryptoCurrency = new Cryptocurrency($config);
    }

    public function route(): void
    {
        if ($this->uri[0] === 'cryptocurrencies') {
            switch ($this->method) {
                case 'GET':
                    $this->GETRoute();
                    break;
                case 'POST':
                    $this->POSTRoute();
                    break;
                case 'PUT':
                    $this->PUTRoute();
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(["error" => "Method not found"]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Not Found"]);
        }
    }

    protected function GETRoute(): void
    {
        switch (count($this->uri)) {
            case 1:
                $this->cryptoCurrency->getAll();
                break;
            case 2:
                $this->cryptoCurrency->getBySymbol($this->uri[1]);
                break;
            default:
                http_response_code(404);
                echo json_encode(["error" => "Route not found"]);
        }
    }

    protected function POSTRoute(): void
    {
        if (isset($this->uri[1]))
            switch ($this->uri[1]) {
                case 'calculate':
                    $input = json_decode(file_get_contents('php://input'), true);
                    if (isset($input['symbol'], $input['amount'], $input['currency'])) {
                        $this->cryptoCurrency->calculateValue($input['symbol'], $input['amount'], $input['currency']);
                    } else {
                        http_response_code(400);
                        echo json_encode(["error" => "Invalid input"]);
                    }
                    break;
                case 'update':
                    $this->cryptoCurrency->updatePrices();
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(["error" => "Route not found"]);
            }
        else {
            http_response_code(404);
            echo json_encode(["error" => "Route not found"]);
        }
    }

    protected function PUTRoute(): void
    {
        if (isset($this->uri[1])) {
            switch ($this->uri[1]) {
                case 'update':
                    $this->cryptoCurrency->updatePrices();
                    break;
                default:
                    http_response_code(404);
                    echo json_encode(["error" => "Route not found"]);
            }
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Route not found"]);
        }
    }


}

$config = require 'config.php';
$router = new Router($config);
$router->route();