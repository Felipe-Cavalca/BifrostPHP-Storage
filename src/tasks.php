<?php

class Tasks
{
    private static $redis;
    private static $activeRequestsKey = 'active_requests'; // Chave no Redis para contar requisições ativas

    public function __construct()
    {
        if (empty(self::$redis)) {
            self::conn();
        }
    }

    private static function conn(): void
    {
        self::$redis = new Redis();
        self::$redis->connect(getenv('REDIS_HOST'), getenv('REDIS_PORT') ?? 6379);
    }

    /**
     * Incrementa o número de requisições ativas no Redis.
     */
    public function incrActiveRequests(): void
    {
        self::$redis->incr(self::$activeRequestsKey);
    }

    /**
     * Decrementa o número de requisições ativas no Redis.
     */
    public function decrActiveRequests(): void
    {
        $count = self::$redis->get(self::$activeRequestsKey);

        if ($count > 0) {
            self::$redis->decr(self::$activeRequestsKey);
        }
    }

    /**
     * Adiciona uma tarefa no começo da fila (prioridade alta).
     */
    public function addToFrontOfQueue(string $queue, array $task): void
    {
        $taskJson = json_encode($task);
        self::$redis->lpush($queue, $taskJson);
    }

    /**
     * Adiciona uma tarefa no final da fila (processamento normal).
     */
    public function addToEndOfQueue(string $queue, array $task): void
    {
        $taskJson = json_encode($task);
        self::$redis->rpush($queue, $taskJson);
    }

    /**
     * Adiciona uma tarefa agendada para rodar daqui a X segundos.
     */
    public function addScheduledTask(string $queue, array $task, int $seconds): void
    {
        $timestamp = time() + $seconds; // Calcula o tempo futuro
        $taskJson = json_encode($task);
        self::$redis->zAdd($queue, $timestamp, $taskJson);
    }
}
