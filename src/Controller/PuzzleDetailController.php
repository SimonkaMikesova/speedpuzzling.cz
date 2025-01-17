<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetPuzzleSolvers  $getPuzzleSolvers,
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetRanking $getRanking,
    ) {
    }

    #[Route(path: ['/puzzle/{puzzleId}', '/skladam-puzzle/{puzzleId}'], name: 'puzzle_detail', methods: ['GET'])]
    public function __invoke(string $puzzleId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
            $puzzleSolvers = $this->getPuzzleSolvers->soloByPuzzleId($puzzleId);
            $groupPuzzleSolvers = $this->getPuzzleSolvers->groupsByPuzzleId($puzzleId);
        } catch (PuzzleNotFound) {
            $this->addFlash('primary', 'Puzzle, které jste zkoušeli hledat, u nás nejsou!');

            return $this->redirectToRoute('puzzles');
        }

        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );

        $userRanking = [];

        if ($user !== null) {
            $playerProfile = $this->getPlayerProfile->byUserId($user->getUserIdentifier());
            $userRanking = $this->getRanking->allForPlayer($playerProfile->playerId);
        }

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'solo_puzzle_solvers' => $this->groupSoloPuzzles($puzzleSolvers),
            'group_puzzle_solvers' => $groupPuzzleSolvers,
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'ranking' => $userRanking,
        ]);
    }

    /**
     * @param array<PuzzleSolver> $solvers
     * @return array<string, non-empty-array<PuzzleSolver>>
     */
    private function groupSoloPuzzles(array $solvers): array
    {
        $grouped = [];

        foreach ($solvers as $solver) {
            $grouped[$solver->playerId][] = $solver;
        }

        return $grouped;
    }
}
