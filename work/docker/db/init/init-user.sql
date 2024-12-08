CREATE TABLE user (
    id INT PRIMARY KEY,
    name VARCHAR(32),
    email VARCHAR(32)
);
INSERT user (id, name, email)
VALUES (1, 'John Doe', 'john@example.com');
INSERT user (id, name, email)
VALUES (2, 'Jane Doe', 'jane@example.com');