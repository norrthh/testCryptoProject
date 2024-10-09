CREATE TABLE cryptocurrency
(
    id         SERIAL PRIMARY KEY,
    symbol     VARCHAR(10) UNIQUE NOT NULL,
    name       VARCHAR(255)       NOT NULL,
    price_usd  DECIMAL(15, 2)     NOT NULL,
    updated_at TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO cryptocurrency (symbol, name, price_usd)
VALUES ('BTC', 'Bitcoin', 50000.00),
       ('ETH', 'Ethereum', 3000.00);