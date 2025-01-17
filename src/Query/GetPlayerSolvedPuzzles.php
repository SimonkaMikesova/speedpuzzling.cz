<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Results\GroupSolvedPuzzle;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Results\SolvedPuzzleDetail;

readonly final class GetPlayerSolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byTimeId(string $timeId): SolvedPuzzleDetail
    {
        if (Uuid::isValid($timeId) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle_solving_time.team ->> 'team_id' AS team_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS added_by_player_id,
    pieces_count,
    player.name AS player_name,
    puzzle_solving_time.comment,
    manufacturer.name AS manufacturer_name,
    finished_at,
    CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN
            JSON_AGG(
                JSON_BUILD_OBJECT(
                    'player_id', player_elem ->> 'player_id',
                    'player_name', COALESCE(p.name, player_elem ->> 'player_name'),
                    'player_code', p.code
                )
            )
        ELSE NULL
    END AS players
FROM puzzle_solving_time
    LEFT JOIN LATERAL json_array_elements(puzzle_solving_time.team -> 'puzzlers') AS player_elem ON puzzle_solving_time.team IS NOT NULL
    LEFT JOIN player p ON p.id = (player_elem ->> 'player_id')::UUID
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE puzzle_solving_time.id = :timeId
GROUP BY puzzle_solving_time.id, puzzle.id, player.id, manufacturer.id
SQL;

        /**
         * @var null|array{
         *     time_id: string,
         *     team_id: null|string,
         *     added_by_player_id: string,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     puzzle_alternative_name: null|string,
         *     manufacturer_name: string,
         *     puzzle_image: null|string,
         *     time: int,
         *     pieces_count: int,
         *     comment: null|string,
         *     players: null|string,
         *     finished_at: string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'timeId' => $timeId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PuzzleSolvingTimeNotFound();
        }

        return SolvedPuzzleDetail::fromDatabaseRow($row);

    }

    /**
     * @return array<SolvedPuzzle>
     */
    public function soloByPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    puzzle_solving_time.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    puzzle_solving_time.seconds_to_solve AS time,
    puzzle_solving_time.player_id AS player_id,
    pieces_count,
    player.name AS player_name,
    puzzle_solving_time.comment,
    puzzle_solving_time.tracked_at,
    manufacturer.name AS manufacturer_name,
    puzzle_solving_time.finished_puzzle_photo AS finished_puzzle_photo
FROM puzzle_solving_time
    INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
    INNER JOIN player ON puzzle_solving_time.player_id = player.id
    INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    puzzle_solving_time.player_id = :playerId
    AND puzzle_solving_time.team IS NULL
ORDER BY seconds_to_solve ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): SolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     player_id: string,
             *     player_name: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     tracked_at: string,
             *     finished_puzzle_photo: null|string,
             * } $row
             */

            return SolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<GroupSolvedPuzzle>
     */
    public function inGroupByPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    pst.id as time_id,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.alternative_name AS puzzle_alternative_name,
    puzzle.image AS puzzle_image,
    pst.seconds_to_solve AS time,
    pst.player_id AS added_by_player_id,
    pieces_count,
    finished_puzzle_photo,
    pst.comment,
    manufacturer.name AS manufacturer_name,
    pst.team ->> 'team_id' AS team_id,
    JSON_AGG(
        JSON_BUILD_OBJECT(
            'player_id', player_elem ->> 'player_id',
            'player_name', COALESCE(p.name, player_elem ->> 'player_name')
        )
    ) AS players
FROM puzzle_solving_time pst
INNER JOIN puzzle ON puzzle.id = pst.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id,
LATERAL json_array_elements(pst.team -> 'puzzlers') AS player_elem
LEFT JOIN player p ON p.id = (player_elem ->> 'player_id')::UUID
WHERE
    EXISTS (
        SELECT 1
        FROM json_array_elements(pst.team -> 'puzzlers') AS sub_player_elem
        WHERE (sub_player_elem ->> 'player_id')::UUID = :playerId::UUID
    )
GROUP BY
    pst.id, puzzle.id, manufacturer.id, time
ORDER BY time ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): GroupSolvedPuzzle {
            /**
             * @var array{
             *     time_id: string,
             *     team_id: null|string,
             *     added_by_player_id: string,
             *     player_name: null|string,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     manufacturer_name: string,
             *     puzzle_image: null|string,
             *     time: int,
             *     pieces_count: int,
             *     comment: null|string,
             *     players: string,
             *     finished_puzzle_photo: null|string,
             * } $row
             */

            return GroupSolvedPuzzle::fromDatabaseRow($row);
        }, $data);
    }
}
