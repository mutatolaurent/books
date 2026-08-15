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
// use Symfony\Component\Serializer\SerializerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use App\Entity\Author;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

final class AuthorController extends AbstractController
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Cette méthode permet de récupérer l'ensemble des auteurs.
     *
     * @param AuthorRepository $authorRepository
     * @param SerializerInterface $serializer
     * @return JsonResponse
     */
    #[Route('/api/authors', name: 'author', methods: ['GET'])]
    public function getAllAuthor(
        AuthorRepository $authorRepository,
        SerializerInterface $serializer,
        Request $request,
        TagAwareCacheInterface $cachePool
    ): JsonResponse {
        //$authorList = $authorRepository->findAll();

        // Récupération des paramètres de pagination depuis la requête. Si aucun paramètre n'est fourni, on utilise les valeurs par défaut (page 1, limite 3).
        $page = $request->query->get('page', 1);
        $limit = $request->query->get('limit', 3);

        // Création d'un identifiant unique pour le cache basé sur la page et la limite
        $idCache = "getAllAuthors-" . $page . "-" . $limit;

        // Utilisation du cache pour stocker et récupérer les résultats de la requête
        $jsonAuthorList = $cachePool->get($idCache, function (ItemInterface $item) use ($authorRepository, $page, $limit, $serializer) {
            echo("L'ELEMENT N'EST PAS ENCORE EN CACHE!\n");
            $item->tag("authorsCache");
            $authorList = $authorRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getAuthors']);
            return $serializer->serialize($authorList, 'json', $context);

        });


        //$jsonAuthorList = $serializer->serialize($authorList, 'json', ['groups' => 'getAuthors']);
        return new JsonResponse($jsonAuthorList, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de récupérer un auteur en particulier en fonction de son id.
     *
     * @param Author $author
     * @param SerializerInterface $serializer
     * @return JsonResponse
     */
    #[Route('/api/authors/{id}', name: 'detailAuthor', methods: ['GET'])]
    public function getAuthor(Author $author, SerializerInterface $serializer): JsonResponse
    {
        // Plus besoin de $bookRepository->find($id); Symfony l'a fait pour vous
        if ($author) {
            $context = SerializationContext::create()->setGroups(['getAuthors']);
            $jsonAuthor = $serializer->serialize($author, 'json', $context);
            return new JsonResponse($jsonAuthor, Response::HTTP_OK, [], true);
        }
        // Si Symfony ne trouve pas l'auteur, il lèvera une 404 automatiquement si le ParamConverter est activé.
        // Donc, cette ligne pourrait être rendue inutile dans certains cas,
        // mais elle est utile si vous voulez un contrôle plus fin.
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }

    /**
     * Cette méthode supprime un auteur en fonction de son id.
     * En cascade, les livres associés aux auteurs seront aux aussi supprimés.
     *
     * /!\ Attention /!\
     * pour éviter le problème :
     * "1451 Cannot delete or update a parent row: a foreign key constraint fails"
     * Il faut bien penser rajouter dans l'entité Author, au niveau de la propriété Books, l'option "orphanRemoval=true" dans l'annotation :
     * #[ORM\OneToMany(targetEntity: Book::class, mappedBy: 'author', orphanRemoval: true)]
     *
     * Et resynchronizer la base de données pour appliquer ces modifications.
     * avec : php bin/console doctrine:schema:update --force
     *
     * @param Author $author
     * @param EntityManagerInterface $em
     * @return JsonResponse
     */
    #[Route('/api/authors/{id}', name: 'deleteAuthor', methods: ['DELETE'])]
    public function deleteBook(Author $author, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        // Invalidation du cache pour s'assurer que les données supprimées ne sont plus accessibles
        $cachePool->invalidateTags(["authorsCache"]);

        $em->remove($author);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Cette méthode permet de créer un nouvel auteur. Elle ne permet pas
     * d'associer directement des livres à cet auteur.
     * Exemple de données :
     * {
     *      "lastName": "Malinger",
     *      "firstName": "Jean",
     *      "bookIds": [112, 123, 467]
     * }
     *
     * Le paramètre bookIds est géré "à la main", pour créer l'association
     * entre un auteur et ses livres. Il est optionnel, et peut être vide.
     * S'il est vide, alors l'auteur sera créé sans livres associés.
     * S'il contient des id de livres, alors ces livres seront associés à l'auteur.
     * S'il contient des id de livres qui n'existent pas, alors une erreur sera renvoyée.
     * S'il contient des id de livres qui existent déjà mais qui sont associés à un autre auteur, alors une erreur sera renvoyée.
     * S'il contient des id de livres qui existent déjà mais qui sont associés à cet auteur, alors ces livres seront simplement ignorés.
     *
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param EntityManagerInterface $em
     * @param UrlGeneratorInterface $urlGenerator
     * @param BookRepository $bookRepository
     * @param ValidatorInterface $validator
     * @return JsonResponse
     */
    #[Route('/api/authors', name:"createAuthor", methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un auteur')]
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

        $context = SerializationContext::create()->setGroups(['getAuthors']);
        $jsonAuthor = $serializer->serialize($author, 'json', $context);

        $location = $urlGenerator->generate('detailAuthor', ['id' => $author->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonAuthor, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * Cette méthode permet de mettre à jour un auteur.
     * Exemple de données :
     * {
     *      "lastName": "Malinger",
     *      "firstName": "Jean",
     *      "bookIds": [112, 123, 467]
     * }
     *
     * Le paramètre bookIds est géré "à la main", pour créer l'association
     * entre un auteur et ses livres. Il est optionnel, et peut être vide.
     * S'il est vide, alors l'auteur sera mis à jour sans livres associés.
     * S'il contient des id de livres, alors ces livres seront associés à l'auteur.
     * S'il contient des id de livres qui n'existent pas, alors une erreur sera renvoyée.
     * S'il contient des id de livres qui existent déjà mais qui sont associés à un autre auteur, alors une erreur sera renvoyée.
     * S'il contient des id de livres qui existent déjà mais qui sont associés à cet auteur, alors ces livres seront simplement ignorés
     *
     *
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param Author $currentAuthor
     * @param EntityManagerInterface $em
     * @param BookRepository $bookRepository
     * @return JsonResponse
     */
    #[Route('/api/authors/{id}', name: 'updateAuthor', methods: ['PUT'])]
    public function updateAuthor(
        Author $currentAuthor, // L'auteur existant est injecté par le ParamConverter
        Request $request,
        SerializerInterface $serializer,
        EntityManagerInterface $em,
        BookRepository $bookRepository,
        TagAwareCacheInterface $cache
    ): JsonResponse {
        $content = $request->getContent();
        // $this->logger->error('content : ' . $content);

        try {
            // Désérialiser et mettre à jour l'auteur.
            // Le ParamConverter a déjà récupéré l'auteur, nous utilisons donc l'objet $author existant.
            //$serializer->deserialize($content, Author::class, 'json', ['object_to_populate' => $author]);
            $newAuthor = $serializer->deserialize($request->getContent(), Author::class, 'json');
            $currentAuthor->setFirstName($newAuthor->getFirstName());
            $currentAuthor->setLastName($newAuthor->getLastName());

        } catch (NotNormalizableValueException $e) {
            return new JsonResponse(['error' => 'Invalid data provided for Author update: ' . $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($content, true);
        // $this->logger->error('data : ', ['data' => $data]);
        $bookIds = $data['bookIds'] ?? null; // Utilisez null pour distinguer l'absence du champ d'un tableau vide
        // $this->logger->error('bookIds :', ['bookIds' => $bookIds]);
        // Si bookIds est présent dans le JSON, nous gérons la mise à jour des livres
        if ($bookIds !== null) {
            if (!is_array($bookIds)) {
                return new JsonResponse(['error' => 'bookIds must be an array of integers.'], Response::HTTP_BAD_REQUEST);
            }

            // Supprimer tous les livres actuellement liés à l'auteur avant d'ajouter les nouveaux
            foreach ($currentAuthor->getBooks() as $book) {
                $currentAuthor->removeBook($book);
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
                $currentAuthor->addBook($book);
            }
        }

        $em->persist($currentAuthor);
        $em->flush(); // Pas besoin de persist() car $author est déjà géré par Doctrine

        //$jsonAuthor = $serializer->serialize($author, 'json', ['groups' => 'getAuthors']);

        // On vide le cache.
        $cache->invalidateTags(["authorsCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }

}
