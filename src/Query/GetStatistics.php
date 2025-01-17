<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PlayerStatistics;

readonly final class GetStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PlayerStatistics>
     */
    public function mostActivePlayers(int $limit): array
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    SUM(puzzle_solving_time.seconds_to_solve) AS total_seconds,
    SUM(puzzle.pieces_count) AS total_pieces,
    COUNT(puzzle_solving_time.id) AS solved_puzzles_count
FROM puzzle_solving_time
INNER JOIN player ON player.id = puzzle_solving_time.player_id
INNER JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE
    player.name IS NOT NULL
GROUP BY
    player.id,
    player.name
ORDER BY solved_puzzles_count DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PlayerStatistics {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     total_seconds: int,
             *     total_pieces: int,
             *     solved_puzzles_count: int,
             * } $row
             */

            return PlayerStatistics::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @throws PlayerNotFound
     */
    public function soloForPlayer(string $playerId): PlayerStatistics
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(puzzle_solving_time.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(puzzle_solving_time.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(puzzle.pieces_count), 0) AS total_pieces
FROM player
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.player_id = player.id AND puzzle_solving_time.team IS NULL
LEFT JOIN puzzle ON puzzle.id = puzzle_solving_time.puzzle_id
WHERE
    player.id = :playerId
GROUP BY
    player.id, player.name;
SQL;

        /**
         * @var null|array{
         *     player_id: string,
         *     player_name: null|string,
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerStatistics::fromDatabaseRow($row);
    }

    /**
     * @throws PlayerNotFound
     */
    public function inGroupForPlayer(string $playerId): PlayerStatistics
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    player.name AS player_name,
    COALESCE(SUM(puzzle_solving_time.seconds_to_solve), 0) AS total_seconds,
    COALESCE(COUNT(puzzle.id), 0) AS solved_puzzles_count,
    COALESCE(SUM(CASE
        WHEN puzzle_solving_time.team IS NOT NULL THEN puzzle.pieces_count / json_array_length(puzzle_solving_time.team->'puzzlers')
        ELSE 0
    END), 0) AS total_pieces
FROM player
LEFT JOIN puzzle_solving_time ON EXISTS (
        SELECT 1
        FROM json_array_elements(puzzle_solving_time.team->'puzzlers') AS team_player
        WHERE (team_player->>'player_id')::UUID = player.id
        AND puzzle_solving_time.team IS NOT NULL
    )
LEFT JOIN puzzle ON puzzle_solving_time.puzzle_id = puzzle.id
WHERE
    player.id = :playerId
GROUP BY
    player.id;
SQL;

        /**
         * @var null|array{
         *     player_id: string,
         *     player_name: null|string,
         *     total_seconds: null|int,
         *     total_pieces: null|int,
         *     solved_puzzles_count: int,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerStatistics::fromDatabaseRow($row);
    }
}
