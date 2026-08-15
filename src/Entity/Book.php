<?php

namespace App\Entity;

use App\Repository\BookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
//use Symfony\Component\Serializer\Attribute\Groups;
use JMS\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Hateoas\Configuration\Annotation as Hateoas;
use JMS\Serializer\Annotation as Serializer;
use JMS\Serializer\Annotation\Since;
use ApiPlatform\Metadata\ApiResource;

#[Serializer\XmlRoot('book')]
#[Hateoas\Relation('self', href: "expr('/api/books/' ~ object.getId())", exclusion: new Hateoas\Exclusion(groups: ['getBooks']))]
#[Hateoas\Relation(
    name: 'delete',
    href: new Hateoas\Route(
        name: 'deleteBook',
        parameters: [
            'id' => "expr(object.getId())"
        ]
    ),
    exclusion: new Hateoas\Exclusion(
        groups: ['getBooks'],
        excludeIf: "expr(not is_granted('ROLE_ADMIN'))"
    )
)]
#[Hateoas\Relation(
    name: 'update',
    href: new Hateoas\Route(
        name: 'updateBook',
        parameters: [
            'id' => "expr(object.getId())"
        ]
    ),
    exclusion: new Hateoas\Exclusion(
        groups: ['getBooks'],
        excludeIf: "expr(not is_granted('ROLE_ADMIN'))"
    )
)]
#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ApiResource()]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(["getBooks","getAuthors"])]
    #[Serializer\XmlAttribute]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(["getBooks","getAuthors"])]
    #[Assert\NotBlank(message: "Le titre du livre est obligatoire")]
    #[Assert\Length(min: 1, max: 255, minMessage: "Le titre doit faire au moins {{ limit }} caractères", maxMessage: "Le titre ne peut pas faire plus de {{ limit }} caractères")]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(["getBooks","getAuthors"])]
    private ?string $coverText = null;

    #[ORM\ManyToOne(inversedBy: 'Books')]
    #[Groups(["getBooks"])]
    private ?Author $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(["getBooks"])]
    #[Since("2.0")]
    private ?string $comment = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCoverText(): ?string
    {
        return $this->coverText;
    }

    public function setCoverText(?string $coverText): static
    {
        $this->coverText = $coverText;

        return $this;
    }

    public function getAuthor(): ?Author
    {
        return $this->author;
    }

    public function setAuthor(?Author $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }
}
