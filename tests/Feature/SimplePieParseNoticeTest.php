<?php

namespace Tests\Feature;

use App\Services\Feed\Exceptions\FeedParseException;
use App\Services\Feed\SimplePieFeedParser;
use Tests\TestCase;

/**
 * Flux mal formé dans l'application démarrée (gestionnaire d'erreurs
 * Laravel actif, comme dans un worker) : SimplePie écrit son Notice
 * via error_log() — ni trigger_error(), ni exception — et le parseur
 * lève bien FeedParseException.
 */
class SimplePieParseNoticeTest extends TestCase
{
    public function test_malformed_feed_raises_parse_exception_and_notice_only_reaches_error_log(): void
    {
        $this->assertNotSame(0, error_reporting() & E_USER_NOTICE);

        $logFile = (string) tempnam(sys_get_temp_dir(), 'simplepie-notice-');
        $previousLog = ini_set('error_log', $logFile);

        try {
            try {
                (new SimplePieFeedParser)->parse((string) file_get_contents(base_path('tests/fixtures/feeds/invalid_malformed.xml')));
                $this->fail('FeedParseException attendue.');
            } catch (FeedParseException $e) {
                $this->assertStringContainsString('Mismatched tag', $e->getMessage());
            }

            $logged = (string) file_get_contents($logFile);
            $this->assertStringContainsString('PHP Notice:', $logged);
            $this->assertStringContainsString('Mismatched tag', $logged);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            unlink($logFile);
        }
    }
}
