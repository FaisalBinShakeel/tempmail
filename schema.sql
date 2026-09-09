-- TempMail schema (MySQL 8)
-- Import with:  mysql -u root -p tempmail < schema.sql

CREATE TABLE inboxes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    address       VARCHAR(255) UNIQUE NOT NULL,
    token         CHAR(64) NOT NULL,              -- owner secret, stored in user's cookie
    is_custom     TINYINT(1) DEFAULT 0,
    extensions    TINYINT DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME NOT NULL,
    INDEX (token),
    INDEX (expires_at)
);

CREATE TABLE emails (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    inbox_address  VARCHAR(255) NOT NULL,
    sender_name    VARCHAR(255),
    sender_email   VARCHAR(255),
    subject        TEXT,
    body_text      LONGTEXT,
    body_html      LONGTEXT,
    raw_headers    TEXT,
    has_attachment TINYINT(1) DEFAULT 0,
    is_read        TINYINT(1) DEFAULT 0,
    received_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (inbox_address, id),
    INDEX (received_at)
);

CREATE TABLE rate_limits (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    action       VARCHAR(50) NOT NULL,
    window_start DATETIME NOT NULL,
    count        INT DEFAULT 1,
    UNIQUE KEY uniq_bucket (ip_address, action, window_start),
    INDEX (window_start)
);
