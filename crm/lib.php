<?php
// CRM visience.ro — baza de date, autentificare, ajutoare.

const STARI = [
  'nou'       => 'Nou',
  'contactat' => 'Contactat',
  'oferta'    => 'Ofertă trimisă',
  'negociere' => 'În negociere',
  'castigat'  => 'Câștigat',
  'pierdut'   => 'Pierdut',
];

/**
 * Directorul cu datele. Prima alegere e in afara webroot-ului, ca sa nu fie
 * accesibil din browser. Daca gazduirea nu permite, cade pe un director
 * protejat in httpdocs — oricum e in afara repo-ului, deci deploy-ul nu il atinge.
 */
function dir_date(): string {
  static $dir = null;
  if ($dir !== null) return $dir;

  $variante = [
    dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'visience-date',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'date-crm',
  ];
  foreach ($variante as $d) {
    if (!is_dir($d)) @mkdir($d, 0750, true);
    if (is_dir($d) && is_writable($d)) {
      // daca a ajuns in httpdocs, il inchidem din .htaccess
      if (strpos($d, 'date-crm') !== false && !file_exists($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
      }
      return $dir = $d;
    }
  }
  throw new RuntimeException('Nu pot crea directorul de date. Verifică permisiunile.');
}

function db(): PDO {
  static $pdo = null;
  if ($pdo !== null) return $pdo;

  if (!extension_loaded('pdo_sqlite')) {
    throw new RuntimeException('Extensia pdo_sqlite nu este activă pe server.');
  }
  $pdo = new PDO('sqlite:' . dir_date() . DIRECTORY_SEPARATOR . 'crm.sqlite');
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA journal_mode = WAL');

  $pdo->exec("CREATE TABLE IF NOT EXISTS leaduri (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    creat TEXT NOT NULL,
    actualizat TEXT NOT NULL,
    nume TEXT DEFAULT '',
    email TEXT DEFAULT '',
    telefon TEXT DEFAULT '',
    detalii TEXT DEFAULT '',
    cand TEXT DEFAULT '',
    oras TEXT DEFAULT '',
    pagina TEXT DEFAULT '',
    intrare TEXT DEFAULT '',
    buton TEXT DEFAULT '',
    sursa TEXT DEFAULT '',
    mediu TEXT DEFAULT '',
    campanie TEXT DEFAULT '',
    referinta TEXT DEFAULT '',
    dispozitiv TEXT DEFAULT '',
    vizite INTEGER DEFAULT 1,
    stare TEXT NOT NULL DEFAULT 'nou',
    valoare REAL DEFAULT 0,
    note TEXT DEFAULT '',
    sters TEXT DEFAULT NULL
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS istoric (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id INTEGER NOT NULL,
    cand TEXT NOT NULL,
    text TEXT NOT NULL
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS setari (
    cheie TEXT PRIMARY KEY,
    valoare TEXT NOT NULL
  )");
  // pasii prin formular, fara date personale — doar un id anonim de sesiune
  $pdo->exec("CREATE TABLE IF NOT EXISTS evenimente (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    creat TEXT NOT NULL,
    sesiune TEXT NOT NULL,
    pas INTEGER NOT NULL,
    camp TEXT DEFAULT '',
    sursa TEXT DEFAULT ''
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS pareri (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    creat TEXT NOT NULL,
    pagina TEXT NOT NULL,
    nota TEXT NOT NULL,
    comentariu TEXT DEFAULT ''
  )");
  // baze create inainte de coloanele noi — inainte de indecsi, ca sa existe coloanele
  $are = [];
  foreach ($pdo->query("PRAGMA table_info(leaduri)") as $c) $are[$c['name']] = true;
  foreach (['nume' => "''", 'oras' => "''", 'sters' => 'NULL'] as $col => $imp) {
    if (!isset($are[$col])) $pdo->exec("ALTER TABLE leaduri ADD COLUMN $col TEXT DEFAULT $imp");
  }

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stare ON leaduri(stare)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_creat ON leaduri(creat)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sters ON leaduri(sters)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ev_ses ON evenimente(sesiune)");
  return $pdo;
}

/** Pasii formularului — folositi si de scriptul din site, si de statistici. */
const PASI_FORMULAR = [1 => 'A văzut formularul', 2 => 'A început să completeze', 3 => 'A trimis'];

const CAMPURI_FORMULAR = [
  2 => [
    'telefon' => 'Telefon',
    'email'   => 'Email',
    'detalii' => 'Detalii proiect',
    'cand'    => 'Când poate fi sunat',
  ],
];

/** Desparte "Portofoliu · footer" in pagina si loc. */
function desparte_buton(?string $b): array {
  $b = trim((string)$b);
  if ($b === '') return ['pagina' => '', 'loc' => ''];
  $p = explode(' · ', $b, 2);
  return ['pagina' => $p[0], 'loc' => $p[1] ?? ''];
}

/** Numar de WhatsApp din telefon romanesc: 0771... -> 40771... */
function numar_wa(string $tel): string {
  $n = preg_replace('/\D+/', '', $tel);
  if ($n === '') return '';
  if (strpos($n, '0') === 0) $n = '4' . $n;
  return $n;
}

function setare(string $cheie, ?string $valoare = null) {
  if ($valoare === null) {
    $s = db()->prepare('SELECT valoare FROM setari WHERE cheie = ?');
    $s->execute([$cheie]);
    $r = $s->fetch();
    return $r ? $r['valoare'] : null;
  }
  $s = db()->prepare('INSERT INTO setari (cheie, valoare) VALUES (?, ?)
                      ON CONFLICT(cheie) DO UPDATE SET valoare = excluded.valoare');
  $s->execute([$cheie, $valoare]);
  return $valoare;
}

function jurnal(int $lead_id, string $text): void {
  $s = db()->prepare('INSERT INTO istoric (lead_id, cand, text) VALUES (?, ?, ?)');
  $s->execute([$lead_id, date('Y-m-d H:i:s'), $text]);
}

/** Salveaza un lead venit din formular. Returneaza id-ul sau 0 la eroare. */
function salveaza_lead(array $d): int {
  $acum = date('Y-m-d H:i:s');
  $s = db()->prepare('INSERT INTO leaduri
    (creat, actualizat, email, telefon, detalii, cand, pagina, intrare, buton,
     sursa, mediu, campanie, referinta, dispozitiv, vizite)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $s->execute([
    $acum, $acum,
    $d['email'] ?? '', $d['telefon'] ?? '', $d['detalii'] ?? '', $d['cand'] ?? '',
    $d['pagina'] ?? '', $d['intrare'] ?? '', $d['buton'] ?? '',
    $d['sursa'] ?? '', $d['mediu'] ?? '', $d['campanie'] ?? '',
    $d['referinta'] ?? '', $d['dispozitiv'] ?? '', (int)($d['vizite'] ?? 1),
  ]);
  $id = (int)db()->lastInsertId();
  jurnal($id, 'Lead primit din formular.');
  return $id;
}

/* ---------- autentificare ---------- */

function porneste_sesiune(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name('vscrm');
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/crm/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  ]);
  session_start();
}

function are_parola(): bool { return setare('parola') !== null; }
function autentificat(): bool { return !empty($_SESSION['crm_ok']); }

function verifica_parola(string $parola): bool {
  $hash = setare('parola');
  return $hash !== null && password_verify($parola, $hash);
}

/** Intarziere progresiva dupa incercari gresite, ca sa nu se poata ghici parola. */
function blocat_pana(): int { return (int)(setare('blocat_pana') ?? 0); }

function inregistreaza_esec(): void {
  $n = (int)(setare('esecuri') ?? 0) + 1;
  setare('esecuri', (string)$n);
  if ($n >= 5) setare('blocat_pana', (string)(time() + min(900, 30 * (2 ** ($n - 5)))));
}

function reseteaza_esecuri(): void {
  setare('esecuri', '0');
  setare('blocat_pana', '0');
}

function jeton(): string {
  if (empty($_SESSION['jeton'])) $_SESSION['jeton'] = bin2hex(random_bytes(16));
  return $_SESSION['jeton'];
}

function verifica_jeton(): void {
  if (!hash_equals($_SESSION['jeton'] ?? '', $_POST['jeton'] ?? '')) {
    http_response_code(400);
    exit('Sesiune expirată. Reîncarcă pagina.');
  }
}

/* ---------- ajutoare afisare ---------- */

function h($t): string { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }

function data_ro(string $iso): string {
  $luni = ['ian','feb','mar','apr','mai','iun','iul','aug','sep','oct','nov','dec'];
  $t = strtotime($iso);
  if (!$t) return $iso;
  return date('j', $t) . ' ' . $luni[(int)date('n', $t) - 1] . ' ' . date('Y, H:i', $t);
}

function de_cand(string $iso): string {
  $s = time() - strtotime($iso);
  if ($s < 3600)  return max(1, (int)($s / 60)) . ' min';
  if ($s < 86400) return (int)($s / 3600) . ' h';
  $z = (int)($s / 86400);
  return $z . ($z === 1 ? ' zi' : ' zile');
}

/** Eticheta scurta pentru o pagina: "/" -> "Prima pagină". */
function eticheta_pagina(string $p): string {
  $p = trim($p);
  if ($p === '' ) return '—';
  if ($p === '/' || $p === 'home') return 'Prima pagină';
  return rtrim($p, '/');
}
