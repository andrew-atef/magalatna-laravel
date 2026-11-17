<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GatekeeperFacebookPostJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFixtures;
use Tests\TestCase;

/**
 * Title-length regression: the old 65-char cutter chopped Arabic words
 * MID-WORD in sitemap image:title ("... | تخفيضا", "... | مه").
 *  - formatTitle cap: Latin "(El Fergany)" parenthetical stripped FIRST,
 *    then word-boundary cut (mb_strrpos) — never mid-word.
 *  - flyers:clean-items --repair-titles: one-time DB repair of stored
 *    broken titles (fragment snap / canonical default theme).
 */
final class FlyerTitleLengthTest extends TestCase
{
    use CreatesFixtures;
    use RefreshDatabase;

    public function test_cap_strips_latin_parenthetical_before_cutting(): void
    {
        $name = 'الفيرجاني (El Fergany)';
        $title = 'عروض الفيرجاني (El Fergany) من 9 حتى 19 نوفمبر 2026 | تخفيضات نوفمبر الكبيرة';

        $capped = GatekeeperFacebookPostJob::applyTitleLengthCap($title, $name);

        $this->assertLessThanOrEqual(65, mb_strlen($capped));
        // Latin parenthetical gone — the Arabic date words survive INTACT.
        $this->assertStringNotContainsString('(El Fergany)', $capped);
        $this->assertStringContainsString('من 9 حتى 19 نوفمبر 2026', $capped);
        // No mid-word debris: no line ends inside a word without the full theme.
        $this->assertStringNotContainsString('تخفيضا', str_replace('تخفيضات', '', $capped));
    }

    public function test_cap_word_boundary_cut_never_chops_mid_word(): void
    {
        // Name WITHOUT a Latin parenthetical: the long theme forces the
        // word-boundary cut — the cut must land on a space, not inside a word.
        $name = 'فjöه';
        $longWord = str_repeat('م', 40); // one single 40-char "word" can't be saved
        $title = "عروض {$name} من 1 حتى 30 نوفمبر 2026 | {$longWord} تخفيضات";

        $capped = GatekeeperFacebookPostJob::applyTitleLengthCap($title, $name);

        $this->assertLessThanOrEqual(65, mb_strlen($capped));
        // The intact trailing theme word either survives fully or not at all.
        if (str_contains($capped, 'تخفيضات')) {
            $this->assertStringContainsString(' تخفيضات', $capped);
        }
    }

    public function test_short_titles_pass_through_unchanged(): void
    {
        $title = 'عروض كازيون من 9 حتى 14 سبتمبر 2026 | مجلة العروض والتوفير';

        $this->assertSame($title, GatekeeperFacebookPostJob::applyTitleLengthCap($title, 'كازيون (Kazyon)'));
    }

    public function test_repair_command_fixes_truncated_theme_fragments(): void
    {
        $retailer = $this->createPublishedFlyer([
            'slug' => 'elfergany',
            'name' => 'الفيرجاني (El Fergany)',
        ], [
            'slug' => 'elfergany-9-19-november-2026',
            'valid_from' => '2026-11-09',
            'valid_until' => '2026-11-19',
            'title' => 'عروض الفيرجاني (El Fergany) من 9 حتى 19 نوفمبر 2026 | تخفيضا',
        ]);
        $this->addPage($retailer, 'flyers/pages/p1.webp');

        $retailer2 = $this->createPublishedFlyer([
            'slug' => 'fathalla',
            'name' => 'فتح الله (Fathalla)',
        ], [
            'slug' => 'fathalla-6-november-6-december-2026',
            'valid_from' => '2026-11-06',
            'valid_until' => '2026-12-06',
            'title' => 'عروض فتح الله (Fathalla) من 6 نوفمبر حتى 6 ديسمبر 2026 | مه',
        ]);
        $this->addPage($retailer2, 'flyers/pages/p2.webp');

        // A WELL-FORMED title must stay untouched.
        $retailer3 = $this->createPublishedFlyer([
            'slug' => 'kazyon',
            'name' => 'كازيون (Kazyon)',
        ], [
            'slug' => 'kazyon-wellformed',
            'title' => 'عروض كازيون من 9 حتى 14 سبتمبر 2026 | مجلة العروض والتوفير',
        ]);
        $originalTitle = $retailer3->title;

        $this->artisan('flyers:clean-items', ['--repair-titles' => true])->assertSuccessful();

        $fixed = $retailer->refresh();
        $this->assertLessThanOrEqual(65, mb_strlen((string) $fixed->title));
        $this->assertStringNotContainsString('تخفيضا', (string) $fixed->title);
        $this->assertStringContainsString('| مجلة العروض والتوفير', (string) $fixed->title);
        $this->assertStringContainsString('من 9 حتى 19 نوفمبر 2026', (string) $fixed->title);

        $fixed2 = $retailer2->refresh();
        // 66 chars even without the Latin name → shortest fitting theme wins;
        // whatever fits must be WORD-CLEAN: no mid-word "| مه" debris.
        $this->assertLessThanOrEqual(65, mb_strlen((string) $fixed2->title));
        $this->assertStringNotContainsString('(Fathalla)', (string) $fixed2->title);
        $this->assertStringNotContainsString(' | مه', (string) $fixed2->title);
        $this->assertDoesNotMatchRegularExpression('/\|\s*\S{1,3}$/u', (string) $fixed2->title);

        $this->assertSame($originalTitle, $retailer3->refresh()->title);
    }

    public function test_repair_command_snaps_short_theme_prefix(): void
    {
        $flyer = $this->createPublishedFlyer([
            'slug' => 'beim',
            'name' => 'بيم (Beim)',
        ], [
            'slug' => 'beim-black-friday',
            'title' => 'عروض بيم (Beim) من 20 حتى 30 نوفمبر 2026 | الجمعة',
        ]);

        $this->artisan('flyers:clean-items', ['--repair-titles' => true])->assertSuccessful();

        $this->assertStringContainsString('| الجمعة البيضاء', (string) $flyer->refresh()->title);
    }

    public function test_repair_command_leaves_plausible_long_themes_untouched(): void
    {
        $flyer = $this->createPublishedFlyer([
            'slug' => 'elmontag',
            'name' => 'العثيم (Al Othaim)',
        ], [
            'slug' => 'elmontag-weekly',
            'title' => 'عروض العثيم من 9 حتى 15 سبتمبر 2026 | مجلة العروض',
        ]);
        $original = $flyer->title;

        $this->artisan('flyers:clean-items', ['--repair-titles' => true])->assertSuccessful();

        $this->assertSame($original, $flyer->refresh()->title);
    }

    public function test_repair_command_dry_run_writes_nothing(): void
    {
        $flyer = $this->createPublishedFlyer([
            'slug' => 'fathalla',
            'name' => 'فتح الله (Fathalla)',
        ], [
            'slug' => 'fathalla-truncated',
            'title' => 'عروض فتح الله (Fathalla) من 6 نوفمبر حتى 6 ديسمبر 2026 | مه',
        ]);
        $original = $flyer->title;

        $this->artisan('flyers:clean-items', ['--repair-titles' => true, '--dry-run' => true])->assertSuccessful();

        $this->assertSame($original, $flyer->refresh()->title);
    }
}
