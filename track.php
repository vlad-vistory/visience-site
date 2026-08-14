<?php
// Primeste pasii prin formular si parerile despre pagini.
// Public, dar fara date personale: doar un id anonim de sesiune si numarul pasului.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('{"ok":false}');
}

$brut = file_get_contents('php://input');
$d = json_decode($brut ?: '[]', true);
if (!is_array($d)) { http_response_code(400); exit('{"ok":false}'); }

$taie = function ($cheie, $max, $model = '/[^\p{L}\p{N} _\.\-\/·„”]/u') use ($d) {
  $v = trim((string)($d[$cheie] ?? ''));
  $v = preg_replace($model, '', $v);
  return mb_substr((string)$v, 0, $max);
};

try {
  require_once __DIR__ . '/crm/lib.php';
  $tip = (string)($d['tip'] ?? '');

  if ($tip === 'formular') {
    $sesiune = preg_replace('/[^a-z0-9]/', '', strtolower((string)($d['sesiune'] ?? '')));
    $pas = (int)($d['pas'] ?? 0);
    if ($sesiune === '' || !isset(PASI_FORMULAR[$pas])) { http_response_code(400); exit('{"ok":false}'); }

    $camp = preg_replace('/[^a-z_]/', '', strtolower((string)($d['camp'] ?? '')));
    $sursa = $taie('sursa', 120);

    // un singur rand per (sesiune, pas, camp)
    $exista = db()->prepare('SELECT 1 FROM evenimente WHERE sesiune = ? AND pas = ? AND camp = ? LIMIT 1');
    $exista->execute([mb_substr($sesiune, 0, 40), $pas, $camp]);
    if (!$exista->fetchColumn()) {
      db()->prepare('INSERT INTO evenimente (creat, sesiune, pas, camp, sursa) VALUES (?,?,?,?,?)')
          ->execute([date('Y-m-d H:i:s'), mb_substr($sesiune, 0, 40), $pas, $camp, $sursa]);
    }
    exit('{"ok":true}');
  }

  if ($tip === 'pagina') {
    $pagina = $taie('pagina', 160);
    if ($pagina === '') { http_response_code(400); exit('{"ok":false}'); }
    $v = amprenta_vizitator();
    // o singura vizita per (vizitator, pagina) intr-o ora — reincarcarile nu umfla cifrele
    $exista = db()->prepare("SELECT 1 FROM vizite WHERE vizitator = ? AND pagina = ? AND creat > ? LIMIT 1");
    $exista->execute([$v, $pagina, date('Y-m-d H:i:s', time() - 3600)]);
    if (!$exista->fetchColumn()) {
      db()->prepare('INSERT INTO vizite (creat, pagina, vizitator, dispozitiv, sursa, campanie) VALUES (?,?,?,?,?,?)')
          ->execute([date('Y-m-d H:i:s'), $pagina, $v, dispozitiv_din_ua(), $taie('sursa', 60), $taie('campanie', 80)]);
    }
    exit('{"ok":true}');
  }

  if ($tip === 'apel') {
    $fel = strtolower((string)($d['fel'] ?? ''));
    if (!in_array($fel, ['telefon', 'whatsapp'], true)) { http_response_code(400); exit('{"ok":false}'); }
    db()->prepare('INSERT INTO apeluri (creat, fel, pagina, vizitator, sursa) VALUES (?,?,?,?,?)')
        ->execute([date('Y-m-d H:i:s'), $fel, $taie('pagina', 160), amprenta_vizitator(), $taie('sursa', 60)]);
    exit('{"ok":true}');
  }

  if ($tip === 'parere') {
    $nota = (string)($d['nota'] ?? '');
    if (!in_array($nota, ['da', 'partial', 'nu'], true)) { http_response_code(400); exit('{"ok":false}'); }
    db()->prepare('INSERT INTO pareri (creat, pagina, nota, comentariu) VALUES (?,?,?,?)')
        ->execute([date('Y-m-d H:i:s'), $taie('pagina', 160), $nota, $taie('comentariu', 500)]);
    exit('{"ok":true}');
  }

  http_response_code(400);
  exit('{"ok":false}');
} catch (Throwable $e) {
  @error_log('track: ' . $e->getMessage());
  http_response_code(500);
  exit('{"ok":false}');
}
