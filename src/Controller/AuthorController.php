<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Symfony\Component\Serializer\SerializerInterface;
use App\Entity\Author;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuthorController extends AbstractController
{
    #[Route('/api/authors', name: 'author', methods: ['GET'])]
    public function getAllAuthor(AuthorRepository $authorRepository, SerializerInterface $serializer): JsonResponse
    {
        $authorList = $authorRepository->findAll();

        $jsonAuthorList = $serializer->serialize($authorList, 'json', ['groups' => 'getAuthors']);
        return new JsonResponse($jsonAuthorList, Response::HTTP_OK, [], true);
    }

    #[Route('/api/authors/{id}', name: 'detailAuthor', methods: ['GET'])]
    public function getAuthor(Author $author, SerializerInterface $serializer): JsonResponse
    {
        // Plus besoin de $bookRepository->find($id); Symfony l'a fait pour vous
        if ($author) {
            $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getAuthors']);
            return new JsonResponse($jsonAuthor, Response::HTTP_OK, [], true);
        }
        // Si Symfony ne trouve pas l'auteur, il lèvera une 404 automatiquement si le ParamConverter est activé.
        // Donc, cette ligne pourrait être rendue inutile dans certains cas,
        // mais elle est utile si vous voulez un contrôle plus fin.
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }

    #[Route('/api/authors/{id}', name: 'deleteAuthor', methods: ['DELETE'])]
    public function deleteBook(Author $author, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($author);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/authors', name:"createAuthor", methods: ['POST'])] // Renommé la route et la méthode
    public function createAuthor(
        Request $request,
        SerializerInterface $serializer,
        EntityManagerInterface $em,
        UrlGeneratorInterface $urlGenerator,
        BookRepository $bookRepository,
        ValidatorInterface $validator
    ): JsonResponse {
        $content = $request->getContent();

        try {
            // Désérialiser l'auteur. Le Serializer peut ignorer les champs qu'il ne connaît pas (comme 'bookIds').
            $author = $serializer->deserialize($content, Author::class, 'json');
        } catch (NotNormalizableValueException $e) {
            // Gérer les erreurs de désérialisation si les données ne correspondent pas à l'entité Author
            return new JsonResponse(['error' => 'Invalid data provided for Author creation: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        // On vérifie les erreurs
        $errors = $validator->validate($author);

        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        // Décoder le JSON pour récupérer les bookIds manuellement
        $data = json_decode($content, true);
        $bookIds = $data['bookIds'] ?? [];

        // Si bookIds n'est pas un tableau, ou est vide, continuer sans livres
        if (!is_array($bookIds)) {
            return new JsonResponse(['error' => 'bookIds must be an array of integers.'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier l'existence des livres et les associer à l'auteur
        foreach ($bookIds as $bookId) {
            if (!is_int($bookId)) {
                return new JsonResponse(['error' => sprintf('Invalid book ID provided: %s. Expected integer.', $bookId)], Response::HTTP_BAD_REQUEST);
            }

            $book = $bookRepository->find($bookId);

            if (!$book) {
                return new JsonResponse(['error' => sprintf('Book with ID %d not found.', $bookId)], Response::HTTP_NOT_FOUND);
            }
            // Assurez-vous que votre entité Author a une méthode addBook()
            // et que votre entité Book a une méthode setAuthor() pour gérer la relation.
            $author->addBook($book);
        }

        $em->persist($author);
        $em->flush();

        $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getAuthors']);

        $location = $urlGenerator->generate('detailAuthor', ['id' => $author->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonAuthor, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    #[Route('/api/authors/{id}', name: 'updateAuthor', methods: ['PUT'])]
    public function updateAuthor(
        Author $author, // L'auteur existant est injecté par le ParamConverter
        Request $request,
        SerializerInterface $serializer,
        EntityManagerInterface $em,
        BookRepository $bookRepository
    ): JsonResponse {
        $content = $request->getContent();

        try {
            // Désérialiser et mettre à jour l'auteur.
            // Le ParamConverter a déjà récupéré l'auteur, nous utilisons donc l'objet $author existant.
            $serializer->deserialize($content, Author::class, 'json', ['object_to_populate' => $author]);
        } catch (NotNormalizableValueException $e) {
            return new JsonResponse(['error' => 'Invalid data provided for Author update: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($content, true);
        $bookIds = $data['bookIds'] ?? null; // Utilisez null pour distinguer l'absence du champ d'un tableau vide

        // Si bookIds est présent dans le JSON, nous gérons la mise à jour des livres
        if ($bookIds !== null) {
            if (!is_array($bookIds)) {
                return new JsonResponse(['error' => 'bookIds must be an array of integers.'], Response::HTTP_BAD_REQUEST);
            }

            // Supprimer tous les livres actuellement liés à l'auteur avant d'ajouter les nouveaux
            foreach ($author->getBooks() as $book) {
                $author->removeBook($book);
            }

            // Ajouter les nouveaux livres
            foreach ($bookIds as $bookId) {
                if (!is_int($bookId)) {
                    return new JsonResponse(['error' => sprintf('Invalid book ID provided: %s. Expected integer.', $bookId)], Response::HTTP_BAD_REQUEST);
                }

                $book = $bookRepository->find($bookId);

                if (!$book) {
                    return new JsonResponse(['error' => sprintf('Book with ID %d not found.', $bookId)], Response::HTTP_NOT_FOUND);
                }
                $author->addBook($book);
            }
        }

        $em->flush(); // Pas besoin de persist() car $author est déjà géré par Doctrine

        $jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getAuthors']);

        return new JsonResponse($jsonAuthor, Response::HTTP_OK, [], true);
    }

}
