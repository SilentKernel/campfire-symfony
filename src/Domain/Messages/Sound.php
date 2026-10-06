<?php

declare(strict_types=1);

namespace App\Domain\Messages;

/**
 * Sound (reference/app/models/sound.rb): the built-in `/play <name>` sounds. A sound shows an
 * image (asset path, width, height) or a text next to its play button.
 */
final readonly class Sound
{
    /** name => text, or name => [image asset path, width, height], in Rails' BUILTIN order. */
    private const array BUILTIN = [
        '56k' => ['sounds/56k.webp', 79, 33],
        'bell' => '🔔',
        'bezos' => '😆💭',
        'bueller' => 'anyone?',
        'butts' => '👐 🚬',
        'clowntown' => ['sounds/clowntown.webp', 210, 150],
        'cottoneyejoe' => '🎶🙉🎶 ',
        'crickets' => 'hears crickets chirping',
        'curb' => ['sounds/curb.webp', 150, 101],
        'dadgummit' => 'dad gummit!! 🎣',
        'dangerzone' => ['sounds/dangerzone.webp', 157, 32],
        'danielsan' => '🎆 🏆 🎆',
        'deeper' => ['sounds/top.webp', 188, 80],
        'ballmer' => 'developers!',
        'donotwant' => ['sounds/donotwant.webp', 150, 150],
        'drama' => ['sounds/drama.webp', 300, 16],
        'flawless' => '#flawless',
        'glados' => '🤖💢',
        'gogogo' => 'Go, go, go!',
        'greatjob' => ['sounds/greatjob.webp', 79, 16],
        'greyjoy' => '😖🎺',
        'guarantee' => 'guarantees it 👌',
        'heygirl' => '✨💁✨',
        'honk' => 'HONK',
        'horn' => '🐶 ✂️ 🐱',
        'horror' => '💀 💀 💀 💀 💀 💀 💀',
        'inconceivable' => 'doesn\'t think it means what you think it means…',
        'letitgo' => '❄️👩❄️⛄️❄️',
        'live' => 'is DOING IT LIVE',
        'loggins' => ['sounds/loggins.webp', 200, 151],
        'makeitso' => 'make it so 👉',
        'noooo' => '👸💀😒',
        'nyan' => ['sounds/nyan.webp', 36, 15],
        'ohmy' => 'raises an eyebrow 😏',
        'ohyeah' => 'isn\'t playing by the rules',
        'pushit' => ['sounds/pushit.webp', 104, 15],
        'rimshot' => 'plays a rimshot',
        'rollout' => 'is rolling out 🚗',
        'rumble' => ['sounds/rumble.webp', 220, 150],
        'sax' => '🌇🎷🎶',
        'secret' => 'found a secret area 🔑',
        'sexyback' => '🔞',
        'story' => 'and now you know…',
        'tada' => 'plays a fanfare 🎏',
        'tmyk' => '✨ ⭐️ The More You Know ✨ ⭐️',
        'totes' => '😁👍',
        'trololo' => 'трололо',
        'trombone' => 'plays a sad trombone',
        'unix' => 'knows this 💻',
        'vuvuzela' => '======<() ~ ♪ ~♫',
        'what' => ['sounds/what.webp', 100, 131],
        'whoomp' => '👏‼️😎',
        'wups' => 'wups!',
        'yay' => ['sounds/yay.webp', 103, 50],
        'yeah' => ['sounds/yeah.webp', 104, 15],
        'yodel' => '📣🗻🙉',
    ];

    /** @param array{string, int, int}|null $image */
    private function __construct(
        public string $name,
        public ?array $image,
        public ?string $text,
    ) {
    }

    /** `Sound.find_by_name(name)` */
    public static function findByName(string $name): ?self
    {
        $definition = self::BUILTIN[$name] ?? null;
        if (null === $definition) {
            return null;
        }

        return \is_array($definition) ? new self($name, $definition, null) : new self($name, null, $definition);
    }

    /** `Message#sound`: the sound a message whose whole plain text is `/play <name>` plays. */
    public static function forPlainText(string $plainText): ?self
    {
        return 1 === preg_match('/\A\/play (?<name>\w+)\z/', $plainText, $match) ? self::findByName($match['name']) : null;
    }

    /**
     * `Sound.names`.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $names = array_map(strval(...), array_keys(self::BUILTIN));
        sort($names, \SORT_STRING);

        return $names;
    }

    /** `sound.asset_path`: the mp3. */
    public function assetPath(): string
    {
        return $this->name.'.mp3';
    }
}
