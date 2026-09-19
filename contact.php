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

// paginile /en/ si /fr/ trimit 'pagina' cu prefix, ca sa stim in ce limba raspundem
$pag0      = $curata('pagina', 40);
$en        = strncmp($pag0, 'en-', 3) === 0;
$fr        = strncmp($pag0, 'fr-', 3) === 0;
$multumire = $en ? '/en/thank-you/' : ($fr ? '/fr/merci/'   : '/multumire/');
$contact   = $en ? '/en/contact/'   : ($fr ? '/fr/contact/' : '/contact/');

// honeypot: campul e ascuns, oamenii nu il completeaza
if ($curata('adresa-web') !== '') {
  header('Location: ' . $multumire, true, 303);
  exit;
}

// trimis la mai putin de 2 secunde de la deschiderea paginii = robot
$durata = $curata('durata', 12);
if ($durata !== '' && ctype_digit($durata) && (int)$durata < 2000) {
  header('Location: ' . $multumire, true, 303);
  exit;
}

// cel mult 3 trimiteri pe ora de pe acelasi IP. In spatele Cloudflare IP-ul
// real vine in CF-Connecting-IP. Daca antetul lipseste si cererea vine de la
// un server Cloudflare, nu limitam: altfel toti vizitatorii de pe acel server
// ar imparti aceeasi limita. La fel pentru adresele private (proxy local).
$eCloudflare = function (string $ip): bool {
  $n = ip2long($ip);
  if ($n === false) return false;
  $retele = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
             '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
             '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
             '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22'];
  foreach ($retele as $r) {
    [$baza, $biti] = explode('/', $r);
    $masca = -1 << (32 - (int)$biti);
    if ((ip2long($baza) & $masca) === ($n & $masca)) return true;
  }
  return false;
};
$ipCf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
$ipDirect = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ip = $ipCf !== '' ? $ipCf : ($eCloudflare($ipDirect) ? '' : $ipDirect);
$ipPublic = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
if ($ipPublic && is_file(__DIR__ . '/crm/lib.php')) {
  try {
    require_once __DIR__ . '/crm/lib.php';
    $dirLimite = dir_date() . DIRECTORY_SEPARATOR . 'limite';
    if (!is_dir($dirLimite)) @mkdir($dirLimite, 0750, true);

    // curatenie rara: fisierele mai vechi de o zi nu mai conteaza
    if (mt_rand(1, 50) === 1) {
      foreach ((array)glob($dirLimite . DIRECTORY_SEPARATOR . '*.json') as $vechi) {
        if (is_file($vechi) && filemtime($vechi) < time() - 86400) @unlink($vechi);
      }
    }

    $fis = $dirLimite . DIRECTORY_SEPARATOR . hash('sha256', 'contact|' . $ip) . '.json';
    $h = @fopen($fis, 'c+');
    if ($h) {
      flock($h, LOCK_EX);
      $acum = time();
      $vechi = json_decode((string)stream_get_contents($h), true);
      $recente = array_values(array_filter(is_array($vechi) ? $vechi : [], function ($t) use ($acum) {
        return is_int($t) && $t > $acum - 3600;
      }));
      if (count($recente) >= 3) {
        flock($h, LOCK_UN);
        fclose($h);
        $asteapta = max(60, min($recente) + 3600 - $acum);
        http_response_code(429);
        header('Retry-After: ' . $asteapta);
        header('Content-Type: text/html; charset=UTF-8');
        $mesaj = $en
          ? 'You have already sent a few requests in the last hour. We will call you back soon. If it is urgent, message us on WhatsApp.'
          : ($fr
            ? 'Vous avez déjà envoyé plusieurs demandes dans la dernière heure. Nous vous rappelons bientôt. Si c’est urgent, écrivez-nous sur WhatsApp.'
            : 'Ai trimis deja câteva cereri în ultima oră. Te sunăm noi în curând. Dacă e urgent, scrie-ne pe WhatsApp.');
        echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
          . '<meta name="robots" content="noindex"><title>Visience</title></head>'
          . '<body style="font-family:system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;line-height:1.6;color:#171616">'
          . '<p>' . htmlspecialchars($mesaj, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p><a href="https://wa.me/40771486499" style="color:#4E6C0C">WhatsApp: +40 771 486 499</a></p>'
          . '</body></html>';
        exit;
      }
      $recente[] = $acum;
      ftruncate($h, 0);
      rewind($h);
      fwrite($h, json_encode($recente));
      flock($h, LOCK_UN);
      fclose($h);
    }
  } catch (Throwable $e) {
    @error_log('Limita formular: ' . $e->getMessage());
  }
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
  $inapoi = $pagina === 'home' ? '/?eroare=contact#contact' : $contact . '?eroare=contact';
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

header('Location: ' . $multumire, true, 303);
exit;
