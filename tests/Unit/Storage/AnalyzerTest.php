<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Rails\RailsJson;
use App\Storage\BlobService;
use App\Storage\Processing\Analyzer;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    public function testVideoMetadataMatchesTheAnalyzer(): void
    {
        $probe = json_decode('{"streams":[{"codec_type":"video","width":320,"height":180,"display_aspect_ratio":"16:9","duration":"65.840000"}],"format":{"duration":"65.9"}}', true);
        $metadata = BlobService::mergeMetadata('{"identified":true}', Analyzer::videoMetadata($probe) + ['analyzed' => true]);
        // The seed's alpha-centuri.mov row.
        self::assertSame('{"identified":true,"width":320.0,"height":180.0,"duration":65.84,"display_aspect_ratio":[16,9],"audio":false,"video":true,"analyzed":true}', $metadata);
    }

    public function testRotatedVideoSwapsDimensions(): void
    {
        $probe = json_decode('{"streams":[{"codec_type":"video","width":1920,"height":1080,"side_data_list":[{"side_data_type":"Display Matrix","rotation":-90}]},{"codec_type":"audio"}],"format":{"duration":"3.5"}}', true);
        self::assertSame('{"width":1080.0,"height":1920.0,"duration":3.5,"angle":-90,"audio":true,"video":true}', RailsJson::encode(Analyzer::videoMetadata($probe)));
    }

    public function testAudioMetadata(): void
    {
        $probe = json_decode('{"streams":[{"codec_type":"audio","duration":"1.5","bit_rate":"128000","sample_rate":"44100","tags":{"title":"x"}}]}', true);
        self::assertSame('{"duration":1.5,"bit_rate":128000,"sample_rate":44100,"tags":{"title":"x"}}', RailsJson::encode(Analyzer::audioMetadata($probe)));
    }

    public function testAnalyzerSelection(): void
    {
        self::assertSame(Analyzer::IMAGE, Analyzer::for('image/png'));
        self::assertSame(Analyzer::VIDEO, Analyzer::for('video/quicktime'));
        self::assertSame(Analyzer::NULL, Analyzer::for('text/plain'));
        self::assertFalse(Analyzer::analyzeLater(Analyzer::NULL));
    }
}
