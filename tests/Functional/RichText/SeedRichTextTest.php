<?php

declare(strict_types=1);

namespace App\Tests\Functional\RichText;

use App\Entity\User;
use App\Http\Current;
use App\RichText\Attachables\Attachments;
use App\RichText\Attachables\DoctrineMentionUsers;
use App\RichText\Attachables\OpengraphEmbed;
use App\RichText\Canonicalizer;
use App\RichText\MentionExtractor;
use App\RichText\RichTextRenderer;
use App\Tests\Support\CampfireTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * Every message body in the default seed, rendered by the real services (Doctrine users,
 * avatar_tag, SGIDs from SECRET_KEY_BASE) and compared with what the Rails app renders for it
 * (tests/fixtures/richtext/seed.json, from tests/fixtures/richtext/run_seed.sh).
 */
final class SeedRichTextTest extends CampfireTestCase
{
    /** @return array<string, mixed> */
    private static function seed(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/richtext/seed.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function boot(): void
    {
        self::bootKernel();
        $current = static::getContainer()->get(Current::class);
        \assert($current instanceof Current);
        $current->setRequest(Request::create('http://'.self::seed()['request_host'].'/'));
    }

    private function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        \assert($service instanceof $class);

        return $service;
    }

    public function testRendersEverySeedMessageAsRails(): void
    {
        $this->boot();
        $renderer = $this->service(RichTextRenderer::class);
        $canonicalizer = $this->service(Canonicalizer::class);
        $mentions = $this->service(MentionExtractor::class);
        \assert($renderer instanceof RichTextRenderer && $canonicalizer instanceof Canonicalizer && $mentions instanceof MentionExtractor);

        $bodies = $this->connection()->fetchAllKeyValue("SELECT record_id, body FROM action_text_rich_texts WHERE record_type = 'Message' AND name = 'body'");
        $cases = self::seed()['cases'];
        $this->assertCount(169, $cases);
        foreach ($cases as $case) {
            $id = $case['message_id'];
            $body = $bodies[$id] ?? null;
            $this->assertSame($case['body'], $body, "message $id body");
            if ('text' === $case['content_type']) {
                $this->assertSame($case['presentation']['ok'], $renderer->render($body), "message $id presentation");
            }
            $this->assertSame($case['plain_text']['ok'], $renderer->toPlainText($body), "message $id plain text");
            $this->assertSame($case['editable']['ok'] ?? '', $renderer->forEditor($body), "message $id editor value");
            $this->assertSame($case['mentioned']['ok'], $mentions->userIds($body), "message $id mentions");
            $this->assertSame($case['to_s']['ok'], $renderer->toRenderedHtmlWithLayout($body), "message $id body.to_s");
            if (null !== $body) {
                $this->assertSame($case['canonical']['ok'], $canonicalizer->canonicalize($body), "message $id canonical");
            }
        }
    }

    public function testCanonicalizesSubmittedBodiesAsActionText(): void
    {
        $this->boot();
        $canonicalizer = $this->service(Canonicalizer::class);
        \assert($canonicalizer instanceof Canonicalizer);
        // Plain text bodies (as bots post them) and markup, through ActionText::Content.new(body).to_html
        $cases = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/fixtures/richtext/canonical.json'), true, 512, \JSON_THROW_ON_ERROR)['cases'];
        foreach (array_filter($cases, static fn (array $c): bool => str_starts_with($c['name'], 'extra ')) as $case) {
            $this->assertSame($case['canonical']['ok'], $canonicalizer->canonicalize($case['body']), (string) json_encode($case['body']));
        }
    }

    public function testMentionPartialTemplateMatchesTheAttachmentRendering(): void
    {
        $this->boot();
        $twig = $this->service(Environment::class);
        $users = $this->service(DoctrineMentionUsers::class);
        \assert($twig instanceof Environment && $users instanceof DoctrineMentionUsers);
        foreach ($this->em()->getRepository(User::class)->findAll() as $user) {
            $this->assertSame(
                Attachments::renderMention($users->mentionUser($user)),
                $twig->render('users/_mention.html.twig', ['user' => $user]),
                $user->getName(),
            );
        }
    }

    public function testOpengraphEmbedTemplateMatchesTheAttachmentRendering(): void
    {
        $this->boot();
        $twig = $this->service(Environment::class);
        \assert($twig instanceof Environment);
        foreach ([
            new OpengraphEmbed('https://example.com/a', 'https://example.com/i.png', 'Title <&>', 'Description "quoted"'),
            new OpengraphEmbed('https://example.com/a', null, null, null),
            new OpengraphEmbed(null, 'https://pbs.twimg.com/profile_images/x.jpg', str_repeat('x', 300), str_repeat('y', 600)),
            new OpengraphEmbed(null, null, null, null),
        ] as $embed) {
            $this->assertSame(Attachments::renderOpengraphEmbed($embed), $twig->render('action_text/attachables/_opengraph_embed.html.twig', ['opengraph_embed' => $embed]));
        }
    }
}
