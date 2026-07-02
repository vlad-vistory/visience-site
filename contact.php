<?php
// Formular contact visience.ro — trimite mesajul pe email si duce vizitatorul la /multumire/

$destinatar = 'contact@visience.ro';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Location: /contact/', true, 303);
  exit;
}

$curata = function ($cheie, $max = 400) {
  $v = trim((string)($_POST[$cheie] ?? ''));
  $v = str_replace(["\r", "\n", "\0"], ' ', $v);
  return mb_substr($v, 0, $max);
};

// honeypot: campul e ascuns, oamenii nu il completeaza
if ($curata('adresa-web') !== '') {
  header('Location: /multumire/', true, 303);
  exit;
}

$email   = $curata('your-email');
$telefon = $curata('tel-626');
$detalii = $curata('text-477', 2000);
$cand    = $curata('your-subject');
$pagina  = $curata('pagina', 40);

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $email = '';
}

// ne trebuie macar un mod de contact
if ($email === '' && $telefon === '') {
  $inapoi = $pagina === 'home' ? '/?eroare=contact#contact' : '/contact/?eroare=contact';
  header('Location: ' . $inapoi, true, 303);
  exit;
}

$rand = [];
$rand[] = 'Email:    ' . ($email !== '' ? $email : '-');
$rand[] = 'Telefon:  ' . ($telefon !== '' ? $telefon : '-');
$rand[] = 'Detalii:  ' . ($detalii !== '' ? $detalii : '-');
$rand[] = 'Cand:     ' . ($cand !== '' ? $cand : '-');
$rand[] = 'Pagina:   ' . ($pagina !== '' ? $pagina : '-');
$rand[] = 'Data:     ' . date('d.m.Y H:i');
$corp = implode("\n", $rand) . "\n";

$antet = [];
$antet[] = 'From: Visience <formular@visience.ro>';
if ($email !== '') {
  $antet[] = 'Reply-To: ' . $email;
}
$antet[] = 'Content-Type: text/plain; charset=UTF-8';

$subiect = 'Cerere noua de pe visience.ro';
if ($telefon !== '') {
  $subiect .= ' — ' . $telefon;
}

mail($destinatar, $subiect, $corp, implode("\r\n", $antet));

header('Location: /multumire/', true, 303);
exit;
