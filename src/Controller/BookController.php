<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\BookRepository;
use App\Repository\AuthorRepository;
// use Symfony\Component\Serializer\SerializerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
// use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use App\Service\VersioningService;
use Nelmio\ApiDocBundle\Attribute\Model;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;

class BookController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des livres.
     *
     * @param BookRepository $bookRepository
     * @param SerializerInterface $serializer
     * @return JsonResponse
     */
    #[Route('/api/books', name: 'book', methods: ['GET'])]
    #[OA\Response(
        response: 200,
        description: 'Retourne la liste des livres',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(ref: new Model(type: Book::class, groups: ['getBooks']))
        )
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        description: "La page que l'on veut récupérer",
        schema: new OA\Schema(type: 'int')
    )]
    #[OA\Parameter(
        name: 'limit',
        in: 'query',
        description: "Le nombre d'éléments que l'on veut récupérer",
        schema: new OA\Schema(type: 'int')
    )]
    #[OA\Tag(name: 'books')]
    public function getBookList(
        BookRepository $bookRepository,
        SerializerInterface $serializer,
        Request $request,
        TagAwareCacheInterface $cachePool,
        VersioningService $versioningService
    ): JsonResponse {
        // $bookList = $bookRepository->findAll();

        // Récupération des paramètres de pagination depuis la requête. Si aucun paramètre n'est fourni, on utilise les valeurs par défaut (page 1, limite 3).
        $page = $request->query->get('page', 1);
        $limit = $request->query->get('limit', 3);

        // Création d'un identifiant unique pour le cache basé sur la page et la limite
        $idCache = "getAllBooks-" . $page . "-" . $limit;

        // Récupération de la version de l'API à partir du service VersioningService
        $version = $versioningService->getVersion();

        // Utilisation du cache pour stocker et récupérer les résultats de la requête
        $jsonBookList = $cachePool->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer, $version) {
            echo("L'ELEMENT N'EST PAS ENCORE EN CACHE!\n");
            $item->tag("booksCache");
            $bookList = $bookRepository->findAllWithPagination($page, $limit);
            $context = SerializationContext::create()->setGroups(['getBooks']);
            $context->setVersion($version);
            // return $serializer->serialize($bookList, 'json', ['groups' => 'getBooks']);
            return $serializer->serialize($bookList, 'json', $context);

        });

        // $bookList = $bookRepository->findAllWithPagination($page, $limit);

        // Sérialisation de la liste des livres en JSON avec le groupe de sérialisation "getBooks"
        //$jsonBookList = $serializer->serialize($bookList, 'json', ['groups' => 'getBooks']);

        // Retourne la réponse JSON avec le code de statut HTTP 200 (OK) et le contenu sérialisé
        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    /**
     * Cette méthode permet de récupérer un livre en particulier en fonction de son id.
     *
     * @param Book $book
     * @param SerializerInterface $serializer
     * @return JsonResponse
     */
    #[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(Book $book, SerializerInterface $serializer): JsonResponse
    {
        // Plus besoin de $bookRepository->find($id); Symfony l'a fait pour vous
        if ($book) {
            $context = SerializationContext::create()->setGroups(['getBooks']);
            $jsonBook = $serializer->serialize($book, 'json', $context);
            // $jsonBook = $serializer->serialize($book, 'json', ['groups' => 'getBooks']);
            return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
        }
        // Si Symfony ne trouve pas le livre, il lèvera une 404 automatiquement si le ParamConverter est activé.
        // Donc, cette ligne pourrait être rendue inutile dans certains cas,
        // mais elle est utile si vous voulez un contrôle plus fin.
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }

    /**
     * Cette méthode permet de supprimer un livre par rapport à son id.
     *
     * @param Book $book
     * @param EntityManagerInterface $em
     * @return JsonResponse
     */
    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        // Invalidation du cache pour s'assurer que les données supprimées ne sont plus accessibles
        $cachePool->invalidateTags(["booksCache"]);

        // suppression du livre de la base de données
        $em->remove($book);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Cette méthode permet d'insérer un nouveau livre.
     * Exemple de données :
     * {
     *     "title": "Le Seigneur des Anneaux",
     *     "coverText": "C'est l'histoire d'un anneau unique",
     *     "idAuthor": 5
     * }
     *
     * Le paramètre idAuthor est géré "à la main", pour créer l'association
     * entre un livre et un auteur.
     * S'il ne correspond pas à un auteur valide, alors le livre sera considéré comme sans auteur.
     *
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param EntityManagerInterface $em
     * @param UrlGeneratorInterface $urlGenerator
     * @param AuthorRepository $authorRepository
     * @param ValidatorInterface $validator
     * @return JsonResponse
     */
    #[Route('/api/books', name:"createBook", methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un livre')]
    public function createBook(
        Request $request,
        SerializerInterface $serializer,
        EntityManagerInterface $em,
        UrlGeneratorInterface $urlGenerator,
        AuthorRepository $authorRepository,
        ValidatorInterface $validator
    ): JsonResponse {
        $book = $serializer->deserialize($request->getContent(), Book::class, 'json');

        // Récupération de l'ensemble des données envoyées sous forme de tableau
        $content = $request->toArray();

        // On vérifie les erreurs
        $errors = $validator->validate($book);

        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        // Récupération de l'idAuthor. S'il n'est pas défini, alors on met -1 par défaut.
        $idAuthor = $content['idAuthor'] ?? -1;

        // On cherche l'auteur qui correspond et on l'assigne au livre.
        // Si "find" ne trouve pas l'auteur, alors null sera retourné.
        $book->setAuthor($authorRepository->find($idAuthor));

        $em->persist($book);
        $em->flush();

        // Serialisation du livre créé en JSON avec le groupe de sérialisation "getBooks"
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook = $serializer->serialize($book, 'json', $context);

        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * Cette méthode permet de mettre à jour un livre en fonction de son id.
     *
     * Exemple de données :
     * {
     *     "title": "Le Seigneur des Anneaux",
     *     "coverText": "C'est l'histoire d'un anneau unique",
     *     "idAuthor": 5
     * }
     *
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param Book $currentBook
     * @param EntityManagerInterface $em
     * @param AuthorRepository $authorRepository
     * @return JsonResponse
     */
    #[Route('/api/books/{id}', name:"updateBook", methods:['PUT'])]
    public function updateBook(
        Request $request,
        SerializerInterface $serializer,
        Book $currentBook,
        EntityManagerInterface $em,
        AuthorRepository $authorRepository,
        ValidatorInterface $validator,
        TagAwareCacheInterface $cache
    ): JsonResponse {
        $newBook = $serializer->deserialize($request->getContent(), Book::class, 'json');
        $currentBook->setTitle($newBook->getTitle());
        $currentBook->setCoverText($newBook->getCoverText());

        // On vérifie les erreurs
        $errors = $validator->validate($currentBook);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;

        $currentBook->setAuthor($authorRepository->find($idAuthor));

        $em->persist($currentBook);
        $em->flush();

        // On vide le cache.
        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
