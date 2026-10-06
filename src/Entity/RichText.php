<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RichTextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** ActionText::RichText: the HTML body of a polymorphic record (`has_rich_text :body`). */
#[ORM\Entity(repositoryClass: RichTextRepository::class)]
#[ORM\Table(name: 'action_text_rich_texts')]
final class RichText implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    /** The polymorphic `record_type` of this model (for attachments embedded in a rich text). */
    public const RECORD_TYPE = 'ActionText::RichText';

    public function __construct(
        #[ORM\Column(name: 'record_type', type: Types::STRING)]
        private string $recordType,
        #[ORM\Column(name: 'record_id', type: Types::INTEGER)]
        private int $recordId,
        #[ORM\Column(name: 'name', type: Types::STRING)]
        private string $name,
        #[ORM\Column(name: 'body', type: Types::TEXT, nullable: true)]
        private ?string $body = null,
    ) {
    }

    public function getRecordType(): string
    {
        return $this->recordType;
    }

    public function getRecordId(): int
    {
        return $this->recordId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(?string $body): static
    {
        $this->body = $body;

        return $this;
    }
}
