<?php

declare(strict_types=1);

namespace Jot;

use Jot\Article\ArticleExtractor;
use Jot\Article\LengthClassifier;
use Jot\Exception\HttpException;
use Jot\Fetch\HttpClient;
use Jot\Id\IdGenerator;
use Jot\Storage\ArticleRepository;
use Jot\Url\UrlValidator;
use Jot\Web\Html;
use Jot\Web\Copy;
use Jot\Web\PrivacyPolicy;
use Jot\Web\Response;
use Throwable;

final class Application
{
    private const ID_PATTERN = '/^[SML][0123456789ABCDEFGHJKMNPQRSTVWXYZ]{3}$/';
    private const MAX_ID_ATTEMPTS = 32;

    public function __construct(
        private ArticleRepository $articles,
        private UrlValidator $urls,
        private HttpClient $http,
        private ArticleExtractor $extractor,
        private LengthClassifier $classifier,
        private IdGenerator $ids,
        private string $baseUrl,
        private PrivacyPolicy $privacy,
    ) {
    }

    public function handle(string $requestUri, string $method = 'GET'): Response
    {
        try {
            if ($method !== 'GET') {
                return new Response(
                    405,
                    Html::page('Method not allowed', '<p class="eyebrow">Error 405</p><h1>Only GET requests are supported.</h1>'),
                    ['Content-Type' => 'text/html; charset=utf-8', 'Allow' => 'GET'],
                );
            }

            [$path, $query] = $this->splitRequestUri($requestUri);

            if ($path === '/') {
                parse_str($query, $parameters);
                if (array_key_exists('url', $parameters)) {
                    $url = is_string($parameters['url']) ? $parameters['url'] : '';
                    try {
                        return $this->save($url);
                    } catch (HttpException $error) {
                        if ($error->status === 400) {
                            return $this->home($url, $error->publicMessage);
                        }
                        throw $error;
                    }
                }

                return $this->home();
            }

            if ($path === '/privacy') {
                return $this->privacy();
            }

            $candidate = substr($path, 1);

            if (stripos($candidate, 'http://') === 0 || stripos($candidate, 'https://') === 0) {
                return $this->save($candidate . ($query !== '' ? '?' . $query : ''));
            }

            return $this->open(rawurldecode($candidate));
        } catch (HttpException $error) {
            if ($error->getMessage() !== $error->publicMessage) {
                error_log('[Jot] ' . $error->getMessage());
            }

            return $this->error($error->status, $error->publicMessage);
        } catch (Throwable $error) {
            error_log('[Jot] ' . $error::class . ': ' . $error->getMessage());
            return $this->error(500, Copy::UNEXPECTED);
        }
    }

    private function home(string $value = '', ?string $error = null): Response
    {
        $safeValue = Html::escape($value);
        $invalid = $error !== null ? ' aria-invalid="true" aria-describedby="url-error"' : '';
        $errorMarkup = $error !== null
            ? '<p class="form-error" id="url-error" role="alert">' . Html::escape($error) . '</p>'
            : '';
        $content = '<section class="intro" aria-labelledby="home-title">'
            . '<p class="eyebrow">' . Copy::HOME_EYEBROW . '</p>'
            . '<h1 id="home-title">' . Copy::HOME_TITLE . '</h1>'
            . '<p class="support">' . Copy::HOME_SUPPORT . '</p></section>'
            . '<form class="save-form" action="/" method="get" data-save-form>'
            . '<label for="url">' . Copy::URL_LABEL . '</label>'
            . '<input id="url" name="url" type="url" value="' . $safeValue . '"'
            . ' placeholder="' . Copy::URL_PLACEHOLDER . '" required maxlength="4096"'
            . ' inputmode="url" enterkeyhint="go" autocomplete="url" autocapitalize="none"'
            . ' autocorrect="off" spellcheck="false"' . $invalid . '>'
            . $errorMarkup
            . '<button type="submit" data-submit-label="' . Copy::SAVING_ACTION . '">' . Copy::SAVE_ACTION . '</button>'
            . '</form>';

        return new Response($error === null ? 200 : 400, Html::page(Copy::HOME_TITLE, $content));
    }

    private function save(string $url): Response
    {
        $this->urls->validate($url);
        $existingId = $this->articles->findIdByUrl($url);

        if ($existingId !== null) {
            return $this->savedResponse($existingId, $url, true);
        }

        $result = $this->http->get($url);
        $wordCount = $this->extractor->wordCount($result->body);
        $prefix = $this->classifier->prefix($wordCount);

        for ($attempt = 0; $attempt < self::MAX_ID_ATTEMPTS; $attempt++) {
            $id = $this->ids->generate($prefix);
            $insert = $this->articles->insert($id, $url);

            if ($insert->inserted) {
                return $this->savedResponse($id, $url, false);
            }

            if ($insert->existingId !== null) {
                return $this->savedResponse($insert->existingId, $url, true);
            }
        }

        throw new HttpException(500, Copy::UNEXPECTED, 'ID collision retry limit exhausted.');
    }

    private function open(string $candidate): Response
    {
        $id = strtoupper($candidate);

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new HttpException(404, Copy::UNKNOWN_CODE);
        }

        $url = $this->articles->findUrlById($id);

        if ($url === null) {
            throw new HttpException(404, Copy::UNKNOWN_CODE);
        }

        return new Response(302, '', ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    private function savedResponse(string $id, string $url, bool $duplicate): Response
    {
        $safeId = Html::escape($id);
        $safeUrl = Html::escape($url);
        $shortUrl = rtrim($this->baseUrl, '/') . '/' . $id;
        $safeShortUrl = Html::escape($shortUrl);
        $state = $duplicate ? Copy::ALREADY_SAVED : Copy::SAVED;
        $content = <<<HTML
<section class="result" aria-labelledby="result-code">
<p class="eyebrow">{$state}</p>
<h1 class="id" id="result-code">{$safeId}</h1>
<p class="result-help">Write this code in your notebook.</p>
<div class="result-actions">
  <button type="button" class="secondary-button" hidden data-copy-code="{$safeId}">Copy code</button>
  <a class="button-link" href="{$safeShortUrl}">Open article</a>
</div>
<p class="copy-status" aria-live="polite" data-copy-status></p>
<p class="source"><span>Article</span> {$safeUrl}</p>
</section>
HTML;

        return new Response(200, Html::page("Saved as {$id}", $content));
    }

    private function privacy(): Response
    {
        if (!$this->privacy->isComplete()) {
            error_log('[Jot] Missing privacy configuration: ' . implode(', ', $this->privacy->missing()));
            $content = '<p class="eyebrow">Datenschutz</p><h1>Datenschutzhinweise sind nicht verfügbar.</h1>'
                . '<p class="support">Der Betreiber muss die Datenschutzkonfiguration vervollständigen.</p>';
            return new Response(503, Html::page('Datenschutz nicht verfügbar', $content, '', 'de'));
        }

        $value = fn (string $key): string => Html::escape($this->privacy->value($key));
        $address = nl2br($value('JOT_PRIVACY_CONTROLLER_ADDRESS'));
        $email = $value('JOT_PRIVACY_CONTACT_EMAIL');
        $processor = nl2br($value('JOT_PRIVACY_PROCESSOR'));
        $transfers = nl2br($value('JOT_PRIVACY_THIRD_COUNTRY_TRANSFERS'));
        $authority = nl2br($value('JOT_PRIVACY_SUPERVISORY_AUTHORITY'));
        $dataProtectionOfficer = $value('JOT_PRIVACY_DPO_CONTACT');
        $dataProtectionOfficerSection = $dataProtectionOfficer !== ''
            ? "<h2>Datenschutzbeauftragter</h2>\n  <p>{$dataProtectionOfficer}</p>"
            : '';
        $content = <<<HTML
<article class="policy">
  <p class="eyebrow">Datenschutz</p>
  <h1>Datenschutzerklärung</h1>
  <p class="support">Stand: {$value('JOT_PRIVACY_UPDATED_AT')}</p>
  <h2>1. Verantwortlicher</h2>
  <p>{$value('JOT_PRIVACY_CONTROLLER_NAME')}<br>{$address}</p>
  <p>E-Mail: <a href="mailto:{$email}">{$email}</a></p>
  {$dataProtectionOfficerSection}
  <h2>2. Verarbeitete Daten</h2>
  <p>Beim Aufruf dieser Website verarbeitet der Server technisch erforderliche Verbindungsdaten. Dazu können insbesondere IP-Adresse, Datum und Uhrzeit des Zugriffs, aufgerufener Pfad, HTTP-Methode, übertragene Datenmenge, Referrer sowie Browser- und Betriebssystemangaben gehören.</p>
  <p>Wenn Sie einen Artikel speichern, verarbeitet Jot außerdem die von Ihnen eingegebene URL, den erzeugten Kurzcode und Zeitstempel. Eine URL kann selbst personenbezogene Daten enthalten. Der Inhalt der Zielseite wird vorübergehend abgerufen und ausschließlich zur Ermittlung der Artikellänge verarbeitet; der Seiteninhalt wird nicht in der Jot-Datenbank gespeichert.</p>
  <h2>3. Zwecke und Rechtsgrundlagen</h2>
  <p>Die Verarbeitung erfolgt, um die Website bereitzustellen, die angeforderte URL abzurufen, einen Kurzcode zu erzeugen und aufzulösen sowie Missbrauch abzuwehren und technische Fehler zu untersuchen.</p>
  <p>{$value('JOT_PRIVACY_LEGAL_BASIS')}</p>
  <h2>4. Abruf der Zielseite</h2>
  <p>Zum Erstellen eines Codes ruft der Jot-Server die eingegebene URL beim Betreiber der Zielseite ab. Dabei erhält dieser Betreiber technisch bedingt insbesondere die IP-Adresse des Jot-Servers, die angeforderte URL, Zeitpunkt und technische Angaben der Serveranfrage. Ihre eigene IP-Adresse wird von Jot nicht an die Zielseite weitergegeben.</p>
  <h2>5. Empfänger und Auftragsverarbeiter</h2>
  <p>{$processor}</p>
  <p>Darüber hinaus werden personenbezogene Daten nur offengelegt, wenn eine gesetzliche Pflicht besteht oder dies zur Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen erforderlich ist.</p>
  <h2>6. Drittlandübermittlungen</h2>
  <p>{$transfers}</p>
  <h2>7. Speicherdauer</h2>
  <p>Eingegebene URLs, Kurzcodes und zugehörige Zeitstempel: {$value('JOT_PRIVACY_URL_RETENTION')}</p>
  <p>Technische Protokolldaten: {$value('JOT_PRIVACY_LOG_RETENTION')}</p>
  <h2>8. Bereitstellung der Daten</h2>
  <p>Die Eingabe einer URL ist freiwillig. Ohne die URL kann Jot keinen Kurzcode erstellen. Technisch erforderliche Verbindungsdaten fallen beim Aufruf der Website automatisch an; ohne ihre Verarbeitung kann die Website nicht bereitgestellt werden.</p>
  <h2>9. Automatisierte Entscheidungen</h2>
  <p>Es findet keine automatisierte Entscheidungsfindung einschließlich Profiling im Sinne von Art. 22 DSGVO statt. Die automatische Einordnung nach Artikellänge hat keine rechtliche oder vergleichbar erhebliche Wirkung.</p>
  <h2>10. Ihre Rechte</h2>
  <p>Sie haben nach Maßgabe der gesetzlichen Voraussetzungen das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16 DSGVO), Löschung (Art. 17 DSGVO), Einschränkung der Verarbeitung (Art. 18 DSGVO), Datenübertragbarkeit (Art. 20 DSGVO) und Widerspruch gegen eine Verarbeitung auf Grundlage berechtigter Interessen (Art. 21 DSGVO). Beruht eine Verarbeitung auf Ihrer Einwilligung, können Sie diese jederzeit mit Wirkung für die Zukunft widerrufen (Art. 7 Abs. 3 DSGVO).</p>
  <p>Zur Ausübung Ihrer Rechte wenden Sie sich an die oben genannte Kontaktadresse.</p>
  <h2>11. Beschwerderecht</h2>
  <p>Sie haben das Recht, sich nach Art. 77 DSGVO bei einer Datenschutzaufsichtsbehörde zu beschweren. Die für den Verantwortlichen zuständige Behörde ist:</p>
  <p>{$authority}</p>
  <h2>12. Cookies, Tracking und Endgerätezugriffe</h2>
  <p>Jot setzt keine Cookies ein und verwendet keine Analyse-, Werbe- oder Trackingdienste. Jot speichert keine Informationen im Local Storage oder Session Storage Ihres Browsers.</p>
  <p>Wenn Sie die Schaltfläche „Code kopieren“ betätigen, wird der angezeigte Kurzcode auf Ihre ausdrückliche Anforderung in die Zwischenablage Ihres Geräts geschrieben. Dieser Zugriff dient ausschließlich der von Ihnen gewünschten Kopierfunktion (§ 25 Abs. 2 Nr. 2 TDDDG).</p>
  <h2>13. Änderungen</h2>
  <p>Diese Datenschutzerklärung wird angepasst, wenn sich die Verarbeitung oder die rechtlichen Anforderungen ändern. Es gilt die jeweils auf dieser Seite veröffentlichte Fassung.</p>
</article>
HTML;

        return new Response(200, Html::page(
            'Datenschutzerklärung',
            $content,
            'Informationen zur Verarbeitung personenbezogener Daten durch jot.cat.',
            'de',
        ));
    }

    private function error(int $status, string $message): Response
    {
        $safeMessage = Html::escape($message);
        $content = "<section class=\"error-page\"><p class=\"eyebrow\">Error {$status}</p><h1>{$safeMessage}</h1><p><a href=\"/\">Back to Jot</a></p></section>";
        return new Response($status, Html::page('Error', $content));
    }

    /** @return array{string, string} */
    private function splitRequestUri(string $requestUri): array
    {
        $position = strpos($requestUri, '?');

        if ($position === false) {
            return [$requestUri, ''];
        }

        return [substr($requestUri, 0, $position), substr($requestUri, $position + 1)];
    }
}
