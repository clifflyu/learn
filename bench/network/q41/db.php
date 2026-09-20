<?php
// Q41 共用的连接工厂。同一个 MySQL，两种写法：拼接 vs 预处理。
declare(strict_types=1);

const Q41_DSN  = 'mysql:host=learn-mysql;port=3306;dbname=q41_sqli;charset=utf8mb4';
const Q41_USER = 'root';
const Q41_PASS = 'root';

/** 有漏洞的写法用的连接：保持 PDO 默认设置（EMULATE_PREPARES = true） */
function db_vuln(): PDO
{
    return new PDO(Q41_DSN, Q41_USER, Q41_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

/** 安全写法用的连接：关掉模拟预处理，让 MySQL 服务端真正做参数绑定 */
function db_safe(): PDO
{
    return new PDO(Q41_DSN, Q41_USER, Q41_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,   // ← 关键
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
}
