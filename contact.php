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

// salvam si in CRM; daca ceva nu merge acolo, emailul a plecat deja
try {
  require_once __DIR__ . '/crm/lib.php';

  $ref = $curata('referinta', 200);
  $gazda = $ref !== '' ? parse_url($ref, PHP_URL_HOST) : '';
  $gazda = $gazda ? preg_replace('/^www\./', '', $gazda) : '';

  $sursa = $curata('sursa', 60);
  if ($sursa === '') {
    if ($gazda === '' || $gazda === 'visience.ro') $sursa = 'direct';
    elseif (strpos($gazda, 'google.') !== false)   $sursa = 'google';
    elseif (strpos($gazda, 'facebook.') !== false || strpos($gazda, 'fb.') !== false) $sursa = 'facebook';
    elseif (strpos($gazda, 'instagram.') !== false) $sursa = 'instagram';
    elseif (strpos($gazda, 'tiktok.') !== false)    $sursa = 'tiktok';
    elseif (strpos($gazda, 'bing.') !== false)      $sursa = 'bing';
    else $sursa = $gazda;
  }

  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  $dispozitiv = preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 'mobil' : 'desktop';

  salveaza_lead([
    'email'      => $email,
    'telefon'    => $telefon,
    'detalii'    => $detalii,
    'cand'       => $cand,
    'pagina'     => $pagina,
    'intrare'    => $curata('intrare', 200),
    'buton'      => $curata('buton', 120),
    'sursa'      => $sursa,
    'mediu'      => $curata('mediu', 60),
    'campanie'   => $curata('campanie', 120),
    'referinta'  => $gazda,
    'dispozitiv' => $dispozitiv,
    'vizite'     => (int)$curata('vizite', 4),
  ]);
} catch (Throwable $e) {
  @error_log('CRM: ' . $e->getMessage());
}

header('Location: /multumire/', true, 303);
exit;
