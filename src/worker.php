<?php

require __DIR__ . "/storage.php";

class Worker
{
    private static $redis;
    private static $queueName = "task_queue";  // Nome da fila principal
    private static $scheduledQueue = "scheduled_queue"; // Nome da fila de agendamentos
    private static $activeRequestsKey = "active_requests"; // Chave que conta requisições ativas
    private static $lastBackgroundRunKey = 'last_background_task'; // Chave que guarda o último background task
    private $storage;

    public function __construct()
    {
        if (empty(self::$redis)) {
            self::connect();
        }

        if (empty($this->storage)) {
            $this->storage = new storage();
        }
    }

    private static function connect(): void
    {
        self::$redis = new Redis();
        self::$redis->connect(getenv("REDIS_HOST"), getenv("REDIS_PORT") ?? 6379);
    }

    /**
     * Inicia o loop do worker
     */
    public function run()
    {
        echo "Worker iniciado...\n";

        while (true) {
            $this->processScheduledTasks(); // Processa tarefas agendadas
            $processed = $this->processQueue(); // Processa tarefas normais

            if (!$processed) {
                // Se não houver nada na fila e nenhuma requisição ativa, roda a função de background
                $this->checkAndRunBackgroundTask();
            }

            sleep(1); // Evita alto consumo de CPU
        }
    }

    /**
     * Processa as tarefas normais da fila
     */
    private function processQueue(): bool
    {
        $taskJson = self::$redis->lpop(self::$queueName); // Pega a primeira tarefa da fila

        if ($taskJson) {
            $task = json_decode($taskJson, true);
            echo "Processando tarefa: {$task["action"]}...\n";

            // Simula um processamento
            $this->executeTask($task);

            echo "Tarefa ID {$task["action"]} concluída.\n";
            return true;
        }

        return false;
    }

    /**
     * Processa as tarefas agendadas
     */
    private function processScheduledTasks(): void
    {
        $now = time();

        // Busca a primeira tarefa que já pode ser executada
        $tasks = self::$redis->zRangeByScore(self::$scheduledQueue, 0, $now, ["limit" => [0, 1]]);

        if (!empty($tasks)) {
            $taskJson = $tasks[0];
            $task = json_decode($taskJson, true);

            echo "Executando tarefa agendada: {$task["action"]}...\n";
            $this->executeTask($task);

            // Remove do Redis após executar
            self::$redis->zRem(self::$scheduledQueue, $taskJson);
            echo "Tarefa agendada {$task["action"]} concluída.\n";
        }
    }

    /**
     * Executa a tarefa real (pode ser expandido conforme necessário)
     */
    private function executeTask(array $task)
    {

        switch ($task["action"]) {
            case "setFileBase64":
                echo "Salvando arquivo em base64...\n";
                $this->storage->setFileBase64($task["file"], $task["base64Content"]);
                break;
            case "moveToTrash":
                echo "Movendo arquivo para a lixeira...\n";
                $this->storage->moveToTrash($task["file"], $task["storagePath"], $task["trashPath"]);
                break;
            case 'clean_trash':
                echo "Limpando arquivos antigos da lixeira...\n";
                $this->storage->cleanTrash();
                break;
            case 'verify_fix_storage':
                echo "Verificando redundância de arquivos...\n";
                $this->storage->verifyAndFixStorage();
                break;
            default:
                echo "Tarefa desconhecida: {$task["action"]}\n";
        }
    }

    /**
     * Verifica se pode rodar uma tarefa de fundo e a executa
     */
    private function checkAndRunBackgroundTask()
    {
        $queueSize = self::$redis->llen(self::$queueName);
        $activeRequests = self::$redis->get(self::$activeRequestsKey) ?? 0;
        $lastRun = self::$redis->get(self::$lastBackgroundRunKey) ?? 0;
        $now = time();

        // Se não houver tarefas na fila e nenhuma requisição ativa
        if ($queueSize == 0 && $activeRequests == 0) {
            // Verifica se passaram pelo menos 10 minutos desde a última execução
            if ($now - $lastRun >= 600) { // 600 segundos = 10 minutos
                echo "Nenhuma tarefa na fila e nenhuma requisição ativa. Rodando tarefa de fundo...\n";
                $this->backgroundTask();

                // Atualiza o timestamp da última execução no Redis
                self::$redis->set(self::$lastBackgroundRunKey, $now);
            } else {
                echo "Aguardando próximo ciclo de manutenção. Última execução foi há " . ($now - $lastRun) . " segundos.\n";
            }
        }
    }

    /**
     * Função de background que roda quando o sistema está ocioso
     */
    private function backgroundTask()
    {
        echo "Executando manutenção de rotina...\n";

        // Adiciona a limpeza da lixeira na fila
        self::$redis->rpush('task_queue', json_encode([
            'id' => uniqid(),
            'action' => 'clean_trash',
        ]));
        echo "Tarefa de limpeza da lixeira adicionada na fila...\n";

        // Adiciona a verificação de redundância na fila
        self::$redis->rpush('task_queue', json_encode([
            'id' => uniqid(),
            'action' => 'verify_fix_storage',
        ]));
        echo "Tarefa de verificação e correção de storage adicionada na fila...\n";

        echo "Manutenção agendada na fila.\n";
    }
}

// Inicia o worker
$worker = new Worker();
$worker->run();
