<?php
// CRM visience.ro — panou privat pentru lead-urile venite din formular.
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

try { db(); } catch (Throwable $e) {
  http_response_code(500);
  echo '<!doctype html><meta charset="utf-8"><title>CRM</title>';
  echo '<div style="font:15px/1.6 system-ui;max-width:640px;margin:80px auto;padding:0 20px">';
  echo '<h1 style="font-size:20px">CRM-ul nu poate porni</h1><p>' . h($e->getMessage()) . '</p>';
  echo '</div>';
  exit;
}

porneste_sesiune();

/* ---------------- pagini fara autentificare ---------------- */

if (!are_parola()) {
  $eroare = '';
  if (($_POST['actiune'] ?? '') === 'initializare') {
    verifica_jeton();
    $p1 = (string)($_POST['parola'] ?? '');
    $p2 = (string)($_POST['parola2'] ?? '');
    if (mb_strlen($p1) < 10)      $eroare = 'Parola trebuie să aibă cel puțin 10 caractere.';
    elseif ($p1 !== $p2)          $eroare = 'Cele două parole nu coincid.';
    else {
      setare('parola', password_hash($p1, PASSWORD_DEFAULT));
      setare('creat', date('Y-m-d H:i:s'));
      reseteaza_esecuri();
      $_SESSION['crm_ok'] = true;
      session_regenerate_id(true);
      header('Location: /crm/');
      exit;
    }
  }
  ecran_intrare('Prima configurare', 'initializare', $eroare, true);
  exit;
}

if (!autentificat()) {
  $eroare = '';
  if (($_POST['actiune'] ?? '') === 'intrare') {
    verifica_jeton();
    if (blocat_pana() > time()) {
      $eroare = 'Prea multe încercări. Mai așteaptă ' . ceil((blocat_pana() - time()) / 60) . ' minute.';
    } elseif (verifica_parola((string)($_POST['parola'] ?? ''))) {
      reseteaza_esecuri();
      $_SESSION['crm_ok'] = true;
      session_regenerate_id(true);
      header('Location: /crm/');
      exit;
    } else {
      inregistreaza_esec();
      $eroare = 'Parolă greșită.';
      usleep(600000);
    }
  }
  ecran_intrare('Intră în CRM', 'intrare', $eroare, false);
  exit;
}

/* ---------------- actiuni ---------------- */

$actiune = $_POST['actiune'] ?? '';
if ($actiune !== '') {
  verifica_jeton();
  $id = (int)($_POST['id'] ?? 0);
  $acum = date('Y-m-d H:i:s');

  if ($actiune === 'stare' && $id) {
    $noua = $_POST['stare'] ?? 'nou';
    if (isset(STARI[$noua])) {
      $s = db()->prepare('SELECT stare FROM leaduri WHERE id = ?');
      $s->execute([$id]);
      $veche = $s->fetchColumn();
      if ($veche !== false && $veche !== $noua) {
        db()->prepare('UPDATE leaduri SET stare = ?, actualizat = ? WHERE id = ?')
            ->execute([$noua, $acum, $id]);
        jurnal($id, 'Stare: ' . (STARI[$veche] ?? $veche) . ' → ' . STARI[$noua]);
      }
    }
    if (($_POST['ajax'] ?? '') === '1') { header('Content-Type: application/json'); exit('{"ok":true}'); }
  }
  elseif ($actiune === 'nota' && $id) {
    $t = trim((string)($_POST['text'] ?? ''));
    if ($t !== '') {
      jurnal($id, $t);
      db()->prepare('UPDATE leaduri SET actualizat = ? WHERE id = ?')->execute([$acum, $id]);
    }
  }
  elseif ($actiune === 'valoare' && $id) {
    $val = (float)str_replace(',', '.', (string)($_POST['valoare'] ?? '0'));
    db()->prepare('UPDATE leaduri SET valoare = ?, actualizat = ? WHERE id = ?')->execute([$val, $acum, $id]);
    jurnal($id, 'Valoare estimată: ' . number_format($val, 0, ',', '.') . ' lei');
  }
  elseif ($actiune === 'editeaza' && $id) {
    $c = function ($k, $max = 200) { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); };
    db()->prepare('UPDATE leaduri SET nume=?, telefon=?, email=?, oras=?, detalii=?, cand=?, actualizat=? WHERE id=?')
        ->execute([$c('nume', 90), $c('telefon', 40), $c('email', 120), $c('oras', 60),
                   $c('detalii', 2000), $c('cand', 60), $acum, $id]);
    jurnal($id, 'Datele clientului au fost modificate manual.');
  }
  elseif ($actiune === 'cos' && $id) {
    db()->prepare('UPDATE leaduri SET sters = ?, actualizat = ? WHERE id = ?')->execute([$acum, $acum, $id]);
    jurnal($id, 'Mutat în coș.');
    header('Location: /crm/?v=palnie');
    exit;
  }
  elseif ($actiune === 'restaureaza' && $id) {
    db()->prepare('UPDATE leaduri SET sters = NULL, actualizat = ? WHERE id = ?')->execute([$acum, $id]);
    jurnal($id, 'Restaurat din coș.');
  }
  elseif ($actiune === 'sterge-definitiv' && $id) {
    db()->prepare('DELETE FROM leaduri WHERE id = ?')->execute([$id]);
    db()->prepare('DELETE FROM istoric WHERE lead_id = ?')->execute([$id]);
    header('Location: /crm/?v=cos');
    exit;
  }
  elseif ($actiune === 'parola-noua') {
    $p1 = (string)($_POST['parola'] ?? '');
    if (verifica_parola((string)($_POST['veche'] ?? '')) && mb_strlen($p1) >= 10 && $p1 === ($_POST['parola2'] ?? '')) {
      setare('parola', password_hash($p1, PASSWORD_DEFAULT));
      $_SESSION['mesaj'] = 'Parola a fost schimbată.';
    } else {
      $_SESSION['mesaj'] = 'Nu am putut schimba parola (verifică parola veche și lungimea de minim 10 caractere).';
    }
  }
  elseif ($actiune === 'iesire') {
    $_SESSION = [];
    session_destroy();
    header('Location: /crm/');
    exit;
  }

  $inapoi = $_POST['inapoi'] ?? '/crm/';
  header('Location: ' . (strpos($inapoi, '/crm/') === 0 ? $inapoi : '/crm/'));
  exit;
}

/* ---------------- export ---------------- */

$v = $_GET['v'] ?? 'panou';

if ($v === 'export') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="leaduri-visience-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w');
  fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['ID','Data','Stare','Nume','Telefon','Email','Oraș','Detalii','Când','Pagina formular',
               'Pagina de intrare','Locul butonului','Sursă','Mediu','Campanie','Referință','Dispozitiv','Valoare']);
  foreach (db()->query('SELECT * FROM leaduri WHERE sters IS NULL ORDER BY creat DESC') as $l) {
    fputcsv($o, [$l['id'], $l['creat'], STARI[$l['stare']] ?? $l['stare'], $l['nume'], $l['telefon'],
                 $l['email'], $l['oras'], $l['detalii'], $l['cand'], $l['pagina'], $l['intrare'],
                 $l['buton'], $l['sursa'], $l['mediu'], $l['campanie'], $l['referinta'],
                 $l['dispozitiv'], $l['valoare']]);
  }
  exit;
}

/* ---------------- randare ---------------- */

cap($v);

if     ($v === 'palnie')   vedere_palnie();
elseif ($v === 'lead')     vedere_lead((int)($_GET['id'] ?? 0));
elseif ($v === 'analiza')  vedere_analiza();
elseif ($v === 'cos')      vedere_cos();
elseif ($v === 'pareri')   vedere_pareri();
elseif ($v === 'setari')   vedere_setari();
else                       vedere_panou();

subsol();


/* ================= vederi ================= */

function vedere_panou(): void {
  $total = (int)db()->query('SELECT COUNT(*) FROM leaduri WHERE sters IS NULL')->fetchColumn();

  if ($total === 0) {
    echo '<div class="gol"><h2>Încă niciun lead</h2>
      <p>Aici vor apărea automat toate cererile trimise prin formularele de pe site.
      Fiecare lead vine cu pagina de intrare, locul de unde a apăsat și sursa vizitei.</p>
      <a class="b b--acc" href="/contact/" target="_blank">Deschide formularul de contact</a></div>';
    return;
  }

  $q = function (string $unde) { return (int)db()->query("SELECT COUNT(*) FROM leaduri WHERE sters IS NULL AND $unde")->fetchColumn(); };
  $luna  = $q("creat >= date('now','start of month')");
  $lunaT = (int)db()->query("SELECT COUNT(*) FROM leaduri WHERE sters IS NULL
                             AND creat >= date('now','start of month','-1 month')
                             AND creat < date('now','start of month')")->fetchColumn();
  $noi     = $q("stare = 'nou'");
  $lucru   = $q("stare NOT IN ('castigat','pierdut')");
  $cast    = $q("stare = 'castigat'");
  $inchise = $q("stare IN ('castigat','pierdut')");
  $val = (float)db()->query("SELECT COALESCE(SUM(valoare),0) FROM leaduri WHERE sters IS NULL AND stare = 'castigat'")->fetchColumn();
  $rata = $inchise ? round($cast / $inchise * 100) : 0;

  $delta = '';
  if ($lunaT > 0) {
    $d = round(($luna - $lunaT) / $lunaT * 100);
    $delta = '<span class="delta ' . ($d >= 0 ? 'sus' : 'jos') . '">' . ($d >= 0 ? '+' : '') . $d . '% vs luna trecută</span>';
  }

  echo '<div class="cifre cifre--5">';
  cifra('Lead-uri luna asta', $luna, $delta);
  cifra('Neatinse', $noi, $noi ? '<span class="delta jos">de contactat</span>' : '');
  cifra('În lucru', $lucru, '<span class="delta">din ' . $total . ' total</span>');
  cifra('Rata de câștig', $rata . '%', $inchise ? '<span class="delta">din ' . $inchise . ' închise</span>' : '');
  cifra('Valoare câștigată', number_format($val, 0, ',', '.') . ' lei', '');
  echo '</div>';

  echo '<div class="doua">';

  echo '<section class="cutie"><h2>Ultimele cereri</h2><div class="lista">';
  foreach (db()->query('SELECT * FROM leaduri WHERE sters IS NULL ORDER BY creat DESC LIMIT 8') as $l) rand_lead($l);
  echo '</div><a class="mai-mult" href="/crm/?v=palnie">Vezi toată pâlnia →</a></section>';

  echo '<section class="cutie"><h2>Ce buton convertește, pe fiecare pagină</h2>
        <p class="explic">Pe fiecare pagină, din ce loc au pornit oamenii spre formular.</p>';
  butoane_pe_pagina();
  echo '</section>';

  echo '</div>';
}

/** Grupare imbricata: pagina -> locurile din pagina, ordonate dupa cate lead-uri au adus. */
function butoane_pe_pagina(string $unde = ''): void {
  $pagini = [];
  $sql = 'SELECT buton, COUNT(*) n FROM leaduri WHERE sters IS NULL ' . $unde . ' GROUP BY buton';
  foreach (db()->query($sql) as $r) {
    $d = desparte_buton($r['buton']);
    $p = $d['pagina'] !== '' ? $d['pagina'] : 'A intrat direct pe formular';
    if (!isset($pagini[$p])) $pagini[$p] = ['total' => 0, 'locuri' => []];
    $pagini[$p]['total'] += (int)$r['n'];
    if ($d['loc'] !== '') {
      $pagini[$p]['locuri'][$d['loc']] = ($pagini[$p]['locuri'][$d['loc']] ?? 0) + (int)$r['n'];
    }
  }
  if (!$pagini) { echo '<p class="sters">Încă nu sunt date.</p>'; return; }
  uasort($pagini, function ($a, $b) { return $b['total'] <=> $a['total']; });

  echo '<div class="pag-lista">';
  foreach ($pagini as $nume => $e) {
    echo '<div class="pag"><div class="pag__cap"><span>' . h($nume) . '</span><b>' . $e['total'] . '</b></div>';
    if ($e['locuri']) {
      arsort($e['locuri']);
      echo '<ul class="pag__locuri">';
      foreach ($e['locuri'] as $loc => $n) {
        echo '<li><span>' . h($loc) . '</span><b>' . $n . '</b></li>';
      }
      echo '</ul>';
    }
    echo '</div>';
  }
  echo '</div>';
}

function vedere_palnie(): void {
  echo '<div class="bara"><h1>Pâlnia de vânzări</h1>
        <div class="bara__act"><a class="b" href="/crm/?v=cos">Coș</a>
        <a class="b" href="/crm/?v=export">Descarcă CSV</a></div></div>
        <p class="explic explic--sus">Trage cardurile dintr-o coloană în alta ca să schimbi etapa.</p>';

  $pe_stare = [];
  foreach (db()->query("SELECT stare, COUNT(*) n FROM leaduri WHERE sters IS NULL GROUP BY stare") as $r) {
    $pe_stare[$r['stare']] = (int)$r['n'];
  }

  echo '<div class="palnie" id="palnie">';
  foreach (STARI as $cheie => $nume) {
    echo '<div class="coloana coloana--' . $cheie . '" data-stare="' . $cheie . '">';
    echo '<div class="coloana__cap"><span><i class="bulina"></i>' . h($nume) . '</span>
          <b data-nr>' . ($pe_stare[$cheie] ?? 0) . '</b></div>';
    echo '<div class="coloana__corp" data-zona>';
    $s = db()->prepare('SELECT * FROM leaduri WHERE stare = ? AND sters IS NULL ORDER BY creat DESC');
    $s->execute([$cheie]);
    foreach ($s as $l) card_lead($l);
    echo '<p class="coloana__gol">Niciun lead</p>';
    echo '</div></div>';
  }
  echo '</div>';
  script_tragere();
}

function vedere_lead(int $id): void {
  $s = db()->prepare('SELECT * FROM leaduri WHERE id = ?');
  $s->execute([$id]);
  $l = $s->fetch();
  if (!$l) { echo '<p>Lead-ul nu există.</p>'; return; }

  $inCos = !empty($l['sters']);
  $nume  = $l['nume'] ?: ($l['telefon'] ?: ($l['email'] ?: 'Lead #' . $l['id']));
  $inapoi = '/crm/?v=lead&id=' . $l['id'];

  echo '<div class="bara"><a class="b" href="/crm/?v=' . ($inCos ? 'cos' : 'palnie') . '">← Înapoi '
     . ($inCos ? 'la coș' : 'la pâlnie') . '</a></div>';

  if ($inCos) {
    echo '<p class="mesaj mesaj--atentie">Lead-ul este în coș. Îl poți restaura sau șterge definitiv, din dreapta.</p>';
  }

  echo '<div class="lead-cap"><div><h1>' . h($nume) . '</h1>
        <p class="lead-cap__sub">Etapa curentă: <b>' . h(STARI[$l['stare']] ?? $l['stare']) . '</b></p></div>';
  if ($l['telefon']) {
    $wa = numar_wa($l['telefon']);
    echo '<div class="lead-cap__act">';
    if ($wa) echo '<a class="b b--wa" href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>';
    echo '<a class="b b--acc" href="tel:' . h($l['telefon']) . '">Sună</a></div>';
  }
  echo '</div>';

  echo '<div class="doua doua--lead">';

  /* stanga: date + atribuire */
  echo '<section class="cutie">';
  echo '<h2>Contact</h2><dl class="dl">';
  if ($l['nume'])    linie_dl('Nume', h($l['nume']));
  if ($l['telefon']) linie_dl('Telefon', '<a href="tel:' . h($l['telefon']) . '">' . h($l['telefon']) . '</a>');
  if ($l['email'])   linie_dl('Email', '<a href="mailto:' . h($l['email']) . '">' . h($l['email']) . '</a>');
  if ($l['oras'])    linie_dl('Oraș', h($l['oras']));
  if ($l['detalii']) linie_dl('Detalii', h($l['detalii']));
  if ($l['cand'])    linie_dl('Când poate fi sunat', h($l['cand']));
  linie_dl('Primit', data_ro($l['creat']) . ' (acum ' . de_cand($l['creat']) . ')');
  echo '</dl>';

  echo '<div class="edit-zona">' . form_editare($l) . '</div>';

  echo '<h2 style="margin-top:28px">Cum a ajuns aici</h2><dl class="dl">';
  linie_dl('Pagina de intrare', '<b>' . h(eticheta_pagina($l['intrare'])) . '</b>');
  linie_dl('A trimis de pe', h(eticheta_pagina($l['pagina'])));
  $d = desparte_buton($l['buton']);
  if ($d['pagina'] !== '') {
    linie_dl('A apăsat pe pagina', '<b>' . h($d['pagina']) . '</b>');
    if ($d['loc'] !== '') linie_dl('Din', '<b>' . h($d['loc']) . '</b>');
  } else {
    linie_dl('De unde a apăsat', '<span class="sters">a intrat direct pe formular</span>');
  }
  linie_dl('Sursă', h($l['sursa'] ?: 'direct'));
  if ($l['mediu'])      linie_dl('Mediu', h($l['mediu']));
  if ($l['campanie'])   linie_dl('Campanie', h($l['campanie']));
  if ($l['referinta'])  linie_dl('Venit de la', h($l['referinta']));
  if ($l['dispozitiv']) linie_dl('Dispozitiv', h($l['dispozitiv']));
  if ((int)$l['vizite'] > 1) linie_dl('Pagini văzute înainte', (int)$l['vizite']);
  echo '</dl></section>';

  /* dreapta: stare, valoare, istoric */
  echo '<section class="cutie">';
  echo '<h2>Stare</h2><form method="post" class="rand-form">' . campuri_ascunse($l['id'], $inapoi);
  echo '<input type="hidden" name="actiune" value="stare"><select name="stare" onchange="this.form.submit()">';
  foreach (STARI as $k => $n) {
    echo '<option value="' . $k . '"' . ($l['stare'] === $k ? ' selected' : '') . '>' . h($n) . '</option>';
  }
  echo '</select><noscript><button class="b b--acc">Salvează</button></noscript></form>';

  echo '<h2 style="margin-top:26px">Valoare estimată</h2>
        <form method="post" class="rand-form">' . campuri_ascunse($l['id'], $inapoi) . '
        <input type="hidden" name="actiune" value="valoare">
        <input type="text" name="valoare" value="' . h(rtrim(rtrim(number_format((float)$l['valoare'], 2, '.', ''), '0'), '.')) . '" inputmode="decimal">
        <span class="unit">lei</span><button class="b b--acc">Salvează</button></form>';

  echo '<h2 style="margin-top:26px">Istoric și notițe</h2>
        <form method="post" class="nota-form">' . campuri_ascunse($l['id'], $inapoi) . '
        <input type="hidden" name="actiune" value="nota">
        <textarea name="text" rows="2" placeholder="Ce ai discutat, ce urmează..."></textarea>
        <button class="b b--acc">Adaugă</button></form>';

  echo '<ul class="istoric">';
  $h = db()->prepare('SELECT * FROM istoric WHERE lead_id = ? ORDER BY id DESC');
  $h->execute([$l['id']]);
  foreach ($h as $e) {
    echo '<li><time>' . h(data_ro($e['cand'])) . '</time><p>' . nl2br(h($e['text'])) . '</p></li>';
  }
  echo '</ul>';

  echo '<div class="sterge-form">';
  if ($inCos) {
    echo '<form method="post">' . campuri_ascunse($l['id'], '/crm/?v=palnie')
       . '<input type="hidden" name="actiune" value="restaureaza">
          <button class="b b--lat">Restaurează</button></form>
          <form method="post" onsubmit="return confirm(\'Ștergi DEFINITIV acest lead? Nu mai poate fi recuperat.\')" style="margin-top:8px">'
       . campuri_ascunse($l['id'], '/crm/?v=cos')
       . '<input type="hidden" name="actiune" value="sterge-definitiv">
          <button class="b b--sterge b--lat">Șterge definitiv</button></form>';
  } else {
    echo '<form method="post" onsubmit="return confirm(\'Muți lead-ul în coș?\')">' . campuri_ascunse($l['id'], '/crm/?v=palnie')
       . '<input type="hidden" name="actiune" value="cos">
          <button class="b b--sterge b--lat">Mută în coș</button></form>';
  }
  echo '</div></section></div>';
}

function form_editare(array $l): string {
  $inapoi = '/crm/?v=lead&id=' . $l['id'];
  $c = function ($k) use ($l) { return h($l[$k] ?? ''); };
  return '<details class="edit"><summary>Editează datele clientului</summary>
    <form method="post" class="edit__f">' . campuri_ascunse((int)$l['id'], $inapoi) . '
    <input type="hidden" name="actiune" value="editeaza">
    <label>Nume<input type="text" name="nume" value="' . $c('nume') . '"></label>
    <label>Telefon<input type="text" name="telefon" value="' . $c('telefon') . '"></label>
    <label>Email<input type="email" name="email" value="' . $c('email') . '"></label>
    <label>Oraș<input type="text" name="oras" value="' . $c('oras') . '"></label>
    <label>Când poate fi sunat<input type="text" name="cand" value="' . $c('cand') . '"></label>
    <label class="edit__lat">Detalii<textarea name="detalii" rows="3">' . $c('detalii') . '</textarea></label>
    <button class="b b--acc">Salvează modificările</button></form></details>';
}

function vedere_cos(): void {
  echo '<div class="bara"><h1>Coș</h1><a class="b" href="/crm/?v=palnie">← Înapoi la pâlnie</a></div>
        <p class="explic explic--sus">Lead-uri scoase din pâlnie. Le poți restaura sau șterge definitiv.</p>';
  $randuri = db()->query('SELECT * FROM leaduri WHERE sters IS NOT NULL ORDER BY sters DESC')->fetchAll();
  if (!$randuri) { echo '<div class="gol"><h2>Coșul e gol</h2></div>'; return; }

  echo '<section class="cutie"><table class="tg tg--cos"><thead><tr>
        <th>Lead</th><th>Venit din</th><th>Șters</th><th></th></tr></thead><tbody>';
  foreach ($randuri as $l) {
    $nume = $l['nume'] ?: ($l['telefon'] ?: ($l['email'] ?: 'Lead #' . $l['id']));
    echo '<tr><td><a href="/crm/?v=lead&id=' . $l['id'] . '">' . h($nume) . '</a></td>
          <td class="sters">' . h(eticheta_pagina($l['intrare'])) . '</td>
          <td class="sters">' . h(data_ro($l['sters'])) . '</td>
          <td class="tg__act">
          <form method="post">' . campuri_ascunse((int)$l['id'], '/crm/?v=cos')
        . '<input type="hidden" name="actiune" value="restaureaza">
          <button class="b b--mic">Restaurează</button></form>
          <form method="post" onsubmit="return confirm(\'Ștergi DEFINITIV lead-ul? Nu mai poate fi recuperat.\')">'
        . campuri_ascunse((int)$l['id'], '/crm/?v=cos')
        . '<input type="hidden" name="actiune" value="sterge-definitiv">
          <button class="b b--mic b--sterge">Șterge definitiv</button></form></td></tr>';
  }
  echo '</tbody></table></section>';
}

function vedere_pareri(): void {
  echo '<div class="bara"><h1>Păreri despre pagini</h1></div>
        <p class="explic explic--sus">Răspunsurile la întrebarea „Ți-a fost utilă pagina?" de la finalul articolelor.</p>';
  $randuri = db()->query('SELECT * FROM pareri ORDER BY creat DESC')->fetchAll();
  $nr = function ($n) use ($randuri) { return count(array_filter($randuri, function ($r) use ($n) { return $r['nota'] === $n; })); };

  echo '<div class="cifre">';
  cifra('Total răspunsuri', count($randuri), '');
  cifra('Da', $nr('da'), '<span class="delta sus">utilă</span>');
  cifra('Parțial', $nr('partial'), '');
  cifra('Nu', $nr('nu'), $nr('nu') ? '<span class="delta jos">de îmbunătățit</span>' : '');
  echo '</div>';

  if (!$randuri) { echo '<div class="gol"><h2>Încă niciun răspuns</h2></div>'; return; }

  /* pe pagini */
  $pe_pagina = [];
  foreach ($randuri as $r) {
    $p = $r['pagina'] ?: 'necunoscut';
    if (!isset($pe_pagina[$p])) $pe_pagina[$p] = ['da' => 0, 'partial' => 0, 'nu' => 0];
    $pe_pagina[$p][$r['nota']]++;
  }
  uasort($pe_pagina, function ($a, $b) { return array_sum($b) <=> array_sum($a); });

  echo '<div class="doua"><section class="cutie"><h2>Pe pagini</h2>
        <table class="tg"><thead><tr><th>Pagina</th><th>Da</th><th>Parțial</th><th>Nu</th></tr></thead><tbody>';
  foreach ($pe_pagina as $p => $c) {
    echo '<tr><td>' . h(eticheta_pagina($p)) . '</td><td class="tg__n">' . $c['da'] . '</td>
          <td class="tg__n">' . $c['partial'] . '</td><td class="tg__n">' . $c['nu'] . '</td></tr>';
  }
  echo '</tbody></table></section>';

  echo '<section class="cutie"><h2>Ce au scris</h2><ul class="istoric">';
  $cuText = array_filter($randuri, function ($r) { return trim((string)$r['comentariu']) !== ''; });
  if (!$cuText) echo '<p class="sters">Niciun comentariu scris încă.</p>';
  foreach (array_slice($cuText, 0, 30) as $r) {
    echo '<li><time>' . h(data_ro($r['creat'])) . ' · ' . h(eticheta_pagina($r['pagina'])) . '</time>
          <p>' . nl2br(h($r['comentariu'])) . '</p></li>';
  }
  echo '</ul></section></div>';
}

function vedere_analiza(): void {
  [$de, $pana, $eticheta, $preset] = perioada();
  $undeL = " AND creat >= " . db()->quote($de) . ($pana ? " AND creat < " . db()->quote($pana) : '');
  $total = (int)db()->query("SELECT COUNT(*) FROM leaduri WHERE sters IS NULL $undeL")->fetchColumn();

  echo '<div class="bara"><h1>Analiză</h1><a class="b" href="/crm/?v=export">Descarcă CSV</a></div>';
  filtre_perioada($preset, $eticheta);

  echo '<section class="cutie" style="margin-bottom:20px"><h2>Pâlnia formularului</h2>
        <p class="explic">Câți au ajuns la formular, câți au început să scrie și câți au trimis.</p>';
  palnie_formular($de, $pana);
  echo '</section>';

  if (!$total) {
    echo '<div class="gol"><h2>Niciun lead în perioada aleasă</h2><p>' . h($eticheta) . '</p></div>';
    return;
  }

  echo '<div class="doua">';

  echo '<section class="cutie"><h2>Pagina prin care au intrat pe site</h2>
        <p class="explic">Prima pagină văzută în vizita care s-a terminat cu o cerere.</p>';
  tabel_grupat("SELECT COALESCE(NULLIF(intrare,''),'necunoscut') k, COUNT(*) n
                FROM leaduri WHERE sters IS NULL $undeL GROUP BY k ORDER BY n DESC LIMIT 15", 'Pagina de intrare', $total);
  echo '</section>';

  echo '<section class="cutie"><h2>Ce buton convertește, pe fiecare pagină</h2>
        <p class="explic">Pagina și locul din pagină de unde au pornit spre formular.</p>';
  butoane_pe_pagina($undeL);
  echo '</section>';

  echo '<section class="cutie"><h2>Sursa vizitei</h2>
        <p class="explic">Google, reclame, social sau acces direct.</p>';
  tabel_grupat("SELECT COALESCE(NULLIF(sursa,''),'direct') k, COUNT(*) n
                FROM leaduri WHERE sters IS NULL $undeL GROUP BY k ORDER BY n DESC LIMIT 15", 'Sursă', $total);
  echo '</section>';

  echo '<section class="cutie"><h2>Pagina de pe care au trimis</h2>
        <p class="explic">Unde se afla vizitatorul în momentul trimiterii.</p>';
  tabel_grupat("SELECT COALESCE(NULLIF(pagina,''),'necunoscut') k, COUNT(*) n
                FROM leaduri WHERE sters IS NULL $undeL GROUP BY k ORDER BY n DESC LIMIT 15", 'Pagina', $total);
  echo '</section>';

  echo '</div>';

  echo '<section class="cutie" style="margin-top:20px"><h2>Evoluție lunară</h2><div class="luni">';
  $randuri = db()->query("SELECT strftime('%Y-%m', creat) k, COUNT(*) n FROM leaduri
                          WHERE sters IS NULL GROUP BY k ORDER BY k DESC LIMIT 12")->fetchAll();
  $max = 1;
  foreach ($randuri as $r) $max = max($max, (int)$r['n']);
  foreach (array_reverse($randuri) as $r) {
    echo '<div class="luna"><div class="luna__bara"><span style="height:' . round((int)$r['n'] / $max * 100) . '%"></span></div>
          <b>' . (int)$r['n'] . '</b><small>' . h(substr($r['k'], 5) . '/' . substr($r['k'], 2, 2)) . '</small></div>';
  }
  echo '</div></section>';

  echo '<section class="cutie" style="margin-top:20px"><h2>Conversia prin pâlnia de vânzări</h2><div class="conv">';
  foreach (STARI as $k => $n) {
    if ($k === 'pierdut') continue;
    $c = (int)db()->query("SELECT COUNT(*) FROM leaduri WHERE sters IS NULL AND stare = " . db()->quote($k) . " $undeL")->fetchColumn();
    $p = $total ? round($c / $total * 100) : 0;
    echo '<div class="conv__r"><span class="conv__n">' . h($n) . '</span>
          <div class="conv__b"><i style="width:' . max(2, $p) . '%"></i></div>
          <span class="conv__v">' . $c . '</span></div>';
  }
  echo '</div></section>';
}

/** Pasii prin formular: vazut -> inceput -> trimis, cu abandon si campuri completate. */
function palnie_formular(string $de, ?string $pana): void {
  $unde = 'creat >= ' . db()->quote($de) . ($pana ? ' AND creat < ' . db()->quote($pana) : '');
  $maxim = [];
  $sursaSes = [];
  foreach (db()->query("SELECT sesiune, pas, camp, sursa FROM evenimente WHERE $unde") as $r) {
    $s = $r['sesiune'];
    $maxim[$s] = max($maxim[$s] ?? 0, (int)$r['pas']);
    if (!isset($sursaSes[$s]) && $r['sursa'] !== '') $sursaSes[$s] = $r['sursa'];
  }
  if (!$maxim) { echo '<p class="sters">Încă nu sunt date despre pașii din formular.</p>'; return; }

  $ajunsi = function (int $pas) use ($maxim) {
    return count(array_filter($maxim, function ($v) use ($pas) { return $v >= $pas; }));
  };
  $start = $ajunsi(1);

  $campuri = [];
  foreach (db()->query("SELECT camp, COUNT(DISTINCT sesiune) n FROM evenimente
                        WHERE $unde AND camp <> '' GROUP BY camp") as $r) {
    $campuri[$r['camp']] = (int)$r['n'];
  }

  echo '<div class="pf">';
  $nrPasi = count(PASI_FORMULAR);
  foreach (PASI_FORMULAR as $pas => $nume) {
    $c = $ajunsi($pas);
    $p = $start ? round($c / $start * 100) : 0;
    $urm = $pas < $nrPasi ? $ajunsi($pas + 1) : null;
    echo '<div class="pf__r"><div class="pf__cap"><b>' . $pas . '. ' . h($nume) . '</b>
          <span>' . $c . ' · ' . $p . '%</span></div>
          <div class="pf__b"><i class="' . ($pas === $nrPasi ? 'gata' : '') . '" style="width:' . max(2, $p) . '%"></i></div>';
    if ($urm !== null && $c - $urm > 0) {
      echo '<p class="pf__drop">↓ ' . ($c - $urm) . ' au renunțat aici (' . round(($c - $urm) / max(1, $c) * 100) . '%)</p>';
    }
    if (isset(CAMPURI_FORMULAR[$pas]) && $c > 0) {
      echo '<div class="pf__campuri"><span class="pf__campuri-t">Din cei ' . $c . ' ajunși aici, au completat:</span>';
      foreach (CAMPURI_FORMULAR[$pas] as $k => $et) {
        $n = $campuri[$k] ?? 0;
        echo '<div><span>' . h($et) . '</span><b>' . $n . ' · ' . ($c ? round($n / $c * 100) : 0) . '%</b></div>';
      }
      echo '</div>';
    }
    echo '</div>';
  }
  echo '</div>';

  /* conversie pe sursa (pagina · loc) */
  $peSursa = [];
  foreach ($maxim as $s => $m) {
    $k = $sursaSes[$s] ?? 'necunoscut';
    if (!isset($peSursa[$k])) $peSursa[$k] = ['start' => 0, 'trimis' => 0];
    $peSursa[$k]['start']++;
    if ($m >= $nrPasi) $peSursa[$k]['trimis']++;
  }
  uasort($peSursa, function ($a, $b) { return $b['start'] <=> $a['start']; });
  echo '<h2 style="margin-top:26px">Conversie după locul de unde au venit</h2>
        <table class="tg"><thead><tr><th>Pagina · locul butonului</th><th>Ajunși</th><th>Trimiși</th><th>Rată</th></tr></thead><tbody>';
  foreach ($peSursa as $k => $c) {
    echo '<tr><td>' . h($k) . '</td><td class="tg__n">' . $c['start'] . '</td>
          <td class="tg__n">' . $c['trimis'] . '</td>
          <td class="tg__n">' . ($c['start'] ? round($c['trimis'] / $c['start'] * 100) : 0) . '%</td></tr>';
  }
  echo '</tbody></table>';
}

function vedere_setari(): void {
  echo '<div class="bara"><h1>Setări</h1></div>';
  if (!empty($_SESSION['mesaj'])) {
    echo '<p class="mesaj">' . h($_SESSION['mesaj']) . '</p>';
    unset($_SESSION['mesaj']);
  }
  echo '<section class="cutie" style="max-width:460px">
        <h2>Schimbă parola</h2>
        <form method="post" class="form-vert">' . campuri_ascunse(0, '/crm/?v=setari') . '
        <input type="hidden" name="actiune" value="parola-noua">
        <label>Parola actuală<input type="password" name="veche" autocomplete="current-password" required></label>
        <label>Parola nouă (minim 10 caractere)<input type="password" name="parola" autocomplete="new-password" required></label>
        <label>Repetă parola nouă<input type="password" name="parola2" autocomplete="new-password" required></label>
        <button class="b b--acc">Schimbă parola</button></form></section>';

  echo '<section class="cutie" style="max-width:460px;margin-top:20px"><h2>Unde sunt datele</h2>
        <p class="explic">Baza de date stă în afara folderului public și nu face parte din site,
        deci nu se pierde la publicarea unei versiuni noi.</p>
        <p class="cale">' . h(dir_date()) . '</p>
        <a class="b" href="/crm/?v=export">Descarcă o copie CSV</a></section>';
}

/* ================= bucati reutilizabile ================= */

/** Perioada aleasa din filtre: [de, pana|null, eticheta, preset]. */
function perioada(): array {
  $luni = ['ianuarie','februarie','martie','aprilie','mai','iunie','iulie','august','septembrie','octombrie','noiembrie','decembrie'];
  $de = $_GET['de'] ?? '';
  $pana = $_GET['pana'] ?? '';
  $luna = $_GET['luna'] ?? '';
  $zile = $_GET['zile'] ?? '30';

  $eData = function ($s) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s); };

  if ($eData($de) || $eData($pana)) {
    $d = $eData($de) ? $de . ' 00:00:00' : '1970-01-01 00:00:00';
    $p = $eData($pana) ? date('Y-m-d 00:00:00', strtotime($pana . ' +1 day')) : null;
    return [$d, $p, ($eData($de) ? $de : 'început') . ' – ' . ($eData($pana) ? $pana : 'azi'), ''];
  }
  if (preg_match('/^(\d{4})-(\d{2})$/', (string)$luna, $m)) {
    $d = $luna . '-01 00:00:00';
    $p = date('Y-m-01 00:00:00', strtotime($d . ' +1 month'));
    return [$d, $p, $luni[(int)$m[2] - 1] . ' ' . $m[1], ''];
  }
  if ($zile === 'tot') return ['1970-01-01 00:00:00', null, 'tot timpul', 'tot'];
  $n = in_array($zile, ['7', '30', '90'], true) ? (int)$zile : 30;
  return [date('Y-m-d H:i:s', time() - $n * 86400), null, 'ultimele ' . $n . ' zile', (string)$n];
}

function filtre_perioada(string $preset, string $eticheta): void {
  echo '<div class="filtre">';
  foreach (['7' => '7 zile', '30' => '30 zile', '90' => '90 zile', 'tot' => 'Tot timpul'] as $k => $n) {
    echo '<a class="fbtn' . ($preset === (string)$k ? ' is-on' : '') . '" href="/crm/?v=analiza&zile=' . $k . '">' . h($n) . '</a>';
  }
  echo '<form method="get" class="filtre__f"><input type="hidden" name="v" value="analiza">
        <label>Pe lună<input type="month" name="luna" value="' . h($_GET['luna'] ?? '') . '"></label>
        <button class="b b--mic">Arată</button></form>';
  echo '<form method="get" class="filtre__f"><input type="hidden" name="v" value="analiza">
        <label>De la<input type="date" name="de" value="' . h($_GET['de'] ?? '') . '"></label>
        <label>Până la<input type="date" name="pana" value="' . h($_GET['pana'] ?? '') . '"></label>
        <button class="b b--mic">Aplică</button></form>';
  echo '<span class="filtre__et">Perioadă: <b>' . h($eticheta) . '</b></span></div>';
}

function cap(string $v): void {
  $nav = ['panou' => 'Panou', 'palnie' => 'Pâlnie', 'analiza' => 'Analiză', 'cos' => 'Coș', 'pareri' => 'Păreri', 'setari' => 'Setări'];
  echo '<!doctype html><html lang="ro"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>CRM Visience</title><link rel="stylesheet" href="/crm/crm.css?v=2">
    <link rel="icon" href="/favicon.ico"></head><body>';
  echo '<header class="sus"><a class="marca" href="/crm/">Visience <span>CRM</span></a><nav>';
  foreach ($nav as $k => $n) {
    $activ = ($v === $k || ($v === 'lead' && $k === 'palnie')) ? ' class="activ"' : '';
    echo '<a href="/crm/?v=' . $k . '"' . $activ . '>' . h($n) . '</a>';
  }
  echo '</nav><a class="vezi-site" href="/" target="_blank" rel="noopener">Vezi site-ul ↗</a>
        <form method="post"><input type="hidden" name="jeton" value="' . jeton() . '">
        <input type="hidden" name="actiune" value="iesire">
        <button class="iesire">Ieși</button></form></header><main class="wrap">';
}

function subsol(): void { echo '</main></body></html>'; }

function campuri_ascunse(int $id, string $inapoi): string {
  return '<input type="hidden" name="jeton" value="' . jeton() . '">'
       . '<input type="hidden" name="id" value="' . $id . '">'
       . '<input type="hidden" name="inapoi" value="' . h($inapoi) . '">';
}

function cifra(string $eticheta, $valoare, string $sub): void {
  echo '<div class="cifra"><span class="cifra__e">' . h($eticheta) . '</span>
        <b class="cifra__v">' . h($valoare) . '</b>' . $sub . '</div>';
}

function linie_dl(string $c, string $v): void { echo '<dt>' . h($c) . '</dt><dd>' . $v . '</dd>'; }

function rand_lead(array $l): void {
  $nume = $l['nume'] ?: ($l['telefon'] ?: ($l['email'] ?: 'Lead #' . $l['id']));
  echo '<a class="rand" href="/crm/?v=lead&id=' . $l['id'] . '">
        <span class="pastila pastila--' . $l['stare'] . '">' . h(STARI[$l['stare']] ?? $l['stare']) . '</span>
        <span class="rand__n">' . h($nume) . '</span>
        <span class="rand__p">' . h(eticheta_pagina($l['intrare'])) . '</span>
        <span class="rand__t">' . h(de_cand($l['creat'])) . '</span></a>';
}

function card_lead(array $l): void {
  $nume = $l['nume'] ?: ($l['telefon'] ?: ($l['email'] ?: 'Lead #' . $l['id']));
  $d = desparte_buton($l['buton']);
  echo '<article class="card" draggable="true" data-id="' . $l['id'] . '">';
  echo '<div class="card__sus"><a class="card__cap" href="/crm/?v=lead&id=' . $l['id'] . '">' . h($nume) . '</a>
        <time>' . h(de_cand($l['creat'])) . '</time></div>';
  if ($l['telefon']) echo '<a class="card__tel" href="tel:' . h($l['telefon']) . '">' . h($l['telefon']) . '</a>';
  if ($l['detalii']) echo '<p class="card__d">' . h(mb_substr($l['detalii'], 0, 80)) . '</p>';
  echo '<p class="card__m"><span>' . h(eticheta_pagina($l['intrare'])) . '</span>';
  if ($d['loc'] !== '') echo '<span class="card__b">' . h($d['loc']) . '</span>';
  echo '</p>';
  echo '<div class="card__jos"><form method="post" class="card__f">' . campuri_ascunse((int)$l['id'], '/crm/?v=palnie')
     . '<input type="hidden" name="actiune" value="stare"><select name="stare" onchange="this.form.submit()">';
  foreach (STARI as $k => $n) {
    echo '<option value="' . $k . '"' . ($l['stare'] === $k ? ' selected' : '') . '>' . h($n) . '</option>';
  }
  echo '</select><noscript><button class="b b--mic">ok</button></noscript></form>';
  echo '<form method="post" onsubmit="return confirm(\'Muți lead-ul în coș?\')">' . campuri_ascunse((int)$l['id'], '/crm/?v=palnie')
     . '<input type="hidden" name="actiune" value="cos">
        <button class="card__cos" title="Mută în coș" aria-label="Mută în coș">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
        <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/>
        </svg></button></form></div></article>';
}

function tabel_grupat(string $sql, string $cap, int $total): void {
  echo '<table class="tg"><thead><tr><th>' . h($cap) . '</th><th>Lead-uri</th><th></th></tr></thead><tbody>';
  foreach (db()->query($sql) as $r) {
    $p = $total ? round((int)$r['n'] / $total * 100) : 0;
    echo '<tr><td>' . h(eticheta_pagina($r['k'])) . '</td><td class="tg__n">' . (int)$r['n'] . '</td>
          <td class="tg__b"><i style="width:' . max(2, $p) . '%"></i><span>' . $p . '%</span></td></tr>';
  }
  echo '</tbody></table>';
}

/** Tragerea cardurilor intre coloane. Fara JS, meniul din card face acelasi lucru. */
function script_tragere(): void {
  $j = jeton();
  echo <<<JS
<script>
(function(){
  var luat=null, sursa=null;
  function nr(col){ col.querySelector('[data-nr]').textContent = col.querySelectorAll('.card').length; }
  function toate(){ document.querySelectorAll('.coloana').forEach(nr); }
  document.querySelectorAll('.card').forEach(function(c){
    c.addEventListener('dragstart', function(e){
      luat=c; sursa=c.closest('.coloana');
      c.classList.add('e-luat');
      e.dataTransfer.effectAllowed='move';
      e.dataTransfer.setData('text/plain', c.dataset.id);
    });
    c.addEventListener('dragend', function(){ c.classList.remove('e-luat'); });
  });
  document.querySelectorAll('.coloana').forEach(function(col){
    var zona=col.querySelector('[data-zona]');
    col.addEventListener('dragover', function(e){ e.preventDefault(); col.classList.add('e-tinta'); });
    col.addEventListener('dragleave', function(){ col.classList.remove('e-tinta'); });
    col.addEventListener('drop', function(e){
      e.preventDefault(); col.classList.remove('e-tinta');
      if(!luat || col===sursa) return;
      var vechi=sursa;
      zona.insertBefore(luat, zona.firstChild);
      luat.querySelector('select[name=stare]').value = col.dataset.stare;
      toate();
      var d=new FormData();
      d.append('jeton','$j'); d.append('actiune','stare'); d.append('ajax','1');
      d.append('id', luat.dataset.id); d.append('stare', col.dataset.stare);
      var mutat=luat;
      fetch('/crm/', {method:'POST', body:d, credentials:'same-origin'})
        .then(function(r){ if(!r.ok) throw 0; })
        .catch(function(){
          vechi.querySelector('[data-zona]').appendChild(mutat);
          mutat.querySelector('select[name=stare]').value = vechi.dataset.stare;
          toate(); alert('Nu am putut salva mutarea. Reîncarcă pagina.');
        });
      luat=null;
    });
  });
})();
</script>
JS;
}

function ecran_intrare(string $titlu, string $actiune, string $eroare, bool $dublu): void {
  echo '<!doctype html><html lang="ro"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>CRM Visience</title>
    <link rel="stylesheet" href="/crm/crm.css?v=2"></head><body class="intrare">';
  echo '<form method="post" class="poarta">
    <div class="marca marca--mare">Visience <span>CRM</span></div>
    <h1>' . h($titlu) . '</h1>';
  if ($dublu) echo '<p class="explic">Alege parola cu care vei intra de acum înainte. O știi doar tu — nu e salvată nicăieri în clar.</p>';
  if ($eroare) echo '<p class="eroare">' . h($eroare) . '</p>';
  echo '<input type="hidden" name="jeton" value="' . jeton() . '">
        <input type="hidden" name="actiune" value="' . h($actiune) . '">
        <label>Parolă<input type="password" name="parola" autocomplete="' . ($dublu ? 'new-password' : 'current-password') . '" required autofocus></label>';
  if ($dublu) echo '<label>Repetă parola<input type="password" name="parola2" autocomplete="new-password" required></label>';
  echo '<button class="b b--acc b--lat">' . ($dublu ? 'Creează parola' : 'Intră') . '</button>
        </form></body></html>';
}
