<?php

require_once __DIR__ . "/tasks.php";

class storage
{
    private object $path;
    private string $projectName;

    public function __construct()
    {
        ini_set('display_errors', '0');
        header("Content-Type: application/json");
        $this->path = new class($this) {
            public function __get($name)
            {
                switch ($name) {
                    case 'disks':
                        return getenv("DIR_DISKS") ?: "/disks";
                    case 'storage':
                        return getenv("DIR_STORAGE") ?: "/storage";
                    case 'trash':
                        return getenv("DIR_TRASH") ?: "/trash";
                    case 'logs':
                        return getenv("DIR_LOGS") ?: "/logs";
                    default:
                        return null;
                }
            }
        };
    }

    public function __toString()
    {
        $tasks = new Tasks();
        $tasks->incrActiveRequests();

        $access = $this->verifyToken();

        if ($access["status"]) {
            $this->projectName = $access["details"]["project"];
            return $this->handleResponse($this->runScript());
        }

        http_response_code(401);
        return $this->handleResponse($access);
    }

    public function __get($var)
    {
        switch ($var) {
            case "disks":
                return $this->getAvailableDisks();
            case "failoverTolerance":
                return getenv("FAILOVER_TOLERANCE") ?: 0;
            case "storagePath":
                return sprintf("%s/%s", $this->projectName, $this->path->storage);
            case "trashPath":
                return sprintf("%s/%s", $this->projectName, $this->path->trash);
            case 'storageFilePath':
                return sprintf("%s/%s/%s", $this->projectName, $this->path->storage, $_GET["file"]);
            case 'trashFilePath':
                return sprintf("%s/%s/%s", $this->projectName, $this->path->trash, $_GET["file"]);
            default:
                return $this->$var;
        }
    }

    /**
     * Manipula a resposta da API
     * - Se o retorno for um array, converte para JSON
     * - Se for outro tipo, converte para string
     * @param mixed $return Retorno da API
     * @return string Resposta formatada
     */
    private function handleResponse(mixed $return): string
    {
        $tasks = new Tasks();
        $tasks->decrActiveRequests();

        if (is_array($return)) {
            return json_encode($return);
        } else {
            return (string) $return;
        }
    }

    /**
     * Executa o script conforme o método HTTP
     * @return mixed Retorno da execução
     */
    private function runScript(): mixed
    {
        try {
            switch ($_SERVER["REQUEST_METHOD"]) {
                case "GET":
                    return $this->getFileBase64($this->storageFilePath);
                case "POST":
                    $headers = getallheaders();
                    if (isset($headers["Sync-Upload"]) && $headers["Sync-Upload"] === "true") {
                        return $this->setFileBase64($this->storageFilePath, json_decode(file_get_contents("php://input"), true)["base64Content"]);
                    }

                    $tasks = new Tasks();
                    $tasks->addToFrontOfQueue("task_queue", [
                        "action" => "setFileBase64",
                        "file" => $this->storageFilePath,
                        "base64Content" => json_decode(file_get_contents("php://input"), true)["base64Content"]
                    ]);
                    return [
                        "status" => true,
                        "message" => "Arquivo sendo salvo. Aguarde..."
                    ];
                case "DELETE":
                    $tasks = new Tasks();
                    $tasks->addToEndOfQueue("task_queue", [
                        "action" => "moveToTrash",
                        "file" => $_GET["file"],
                        "storagePath" => $this->storagePath,
                        "trashPath" => $this->trashPath
                    ]);
                    return [
                        "status" => true,
                        "message" => "Arquivo sendo movido para a lixeira. Aguarde..."
                    ];
                default:
                    return [
                        "status" => false,
                        "message" => "Método não suportado"
                    ];
            }
        } catch (Exception $e) {
            return [
                "status" => false,
                "message" => "Erro interno",
                "details" => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica se o token de autorização é válido
     * @return array Status da verificação
     */
    private function verifyToken()
    {
        $headers = getallheaders();

        if (!isset($headers["Authorization"])) {
            return [
                "status" => false,
                "message" => "Acesso negado",
                "details" => "Token não fornecido"
            ];
        }

        $authHeader = $headers["Authorization"];

        $token = str_replace("Bearer ", "", $authHeader);

        foreach ($_ENV as $key => $value) {
            if (strpos($key, "AUTH_") === 0 && $value === $token) {
                return [
                    "status" => true,
                    "message" => "Acesso concedido",
                    "details" => ["project" => substr($key, 5)]
                ];
            }
        }

        return [
            "status" => false,
            "message" => "Acesso negado",
            "details" => "Token inválido"
        ];
    }

    /**
     * Retorna os discos disponíveis para armazenamento
     * - Discos são diretórios dentro de $pathStorage
     * @return array
     */
    private function getAvailableDisks(): array
    {
        $storagePath = $this->path->disks;
        $disks = [];

        if (is_dir($storagePath)) {
            foreach (scandir($storagePath) as $disk) {
                if ($disk !== "." && $disk !== "..") {
                    $diskPath = $storagePath . "/" . $disk;
                    if (is_dir($diskPath) && is_writable($diskPath)) {
                        $disks[] = $diskPath;
                    }
                }
            }
        }

        return $disks;
    }

    /**
     * Retorna os discos disponíveis para armazenamento, ordenados pelo espaço livre.
     * - Se $fileSize for passado, remove discos que não têm espaço suficiente.
     * - Discos com mais de 70% de ocupação perdem prioridade.
     * - O parâmetro `$priority` define se o armazenamento deve priorizar SSDs ou HDDs.
     *
     * @param int|null $fileSize Tamanho do arquivo em bytes (opcional)
     * @param string $priority Tipo de disco preferido ("ssd" ou "hdd")
     * @return array Lista de discos ordenados do melhor para o pior
     */
    private function getBestsDisks(int|null $fileSize = null, string $priority = "ssd"): array
    {
        $disks = $this->disks;
        $ssds = [];
        $hdds = [];
        $highPriority = [];
        $lowPriority = [];

        // Separando os discos entre SSDs e HDDs
        foreach ($disks as $disk) {
            if (strpos($disk, "ssd") !== false) {
                $ssds[] = $disk;
            } else {
                $hdds[] = $disk;
            }
        }

        // Define a lista de prioridade com base no parâmetro
        $preferredDisks = ($priority === "ssd") ? $ssds : $hdds;
        $alternativeDisks = ($priority === "ssd") ? $hdds : $ssds;

        foreach ([$preferredDisks, $alternativeDisks] as $diskList) {
            foreach ($diskList as $disk) {
                $totalSpace = disk_total_space($disk);
                $freeSpace = disk_free_space($disk);
                $usagePercentage = 100 - (($freeSpace / $totalSpace) * 100);

                // Se o tamanho do arquivo foi passado, remove discos sem espaço suficiente
                if ($fileSize !== null && $freeSpace < $fileSize) {
                    continue;
                }

                $diskData = [
                    "disk" => $disk,
                    "free_space" => $freeSpace,
                    "usage_percentage" => $usagePercentage
                ];

                // Se a ocupação for menor que 70%, dá prioridade
                if ($usagePercentage < 70) {
                    $highPriority[] = $diskData;
                } else {
                    $lowPriority[] = $diskData;
                }
            }
        }

        // Ordena ambas as listas pelo espaço livre (maior para menor)
        usort($highPriority, function ($a, $b) {
            return $b["free_space"] <=> $a["free_space"];
        });

        usort($lowPriority, function ($a, $b) {
            return $b["free_space"] <=> $a["free_space"];
        });

        return array_merge($highPriority, $lowPriority);
    }


    /**
     * Salva um arquivo em vários discos para redundância
     * - Se não houver discos suficientes, retorna uma mensagem de erro
     * - Se o arquivo não puder ser salvo, retorna uma mensagem de erro
     * @param string $filePath Caminho do arquivo
     * @param string $base64Content Conteúdo do arquivo em Base64
     * @return array Status da operação
     */
    public function setFileBase64(string $filePath, string $base64Content, string $priority = "ssd"): array
    {
        $fileSize = strlen(base64_decode($base64Content)); // Obtém o tamanho do arquivo em bytes

        $decodedFile = base64_decode($base64Content);
        if ($decodedFile === false) {
            return [
                "status" => false,
                "message" => "Falha ao decodificar Base64",
                "details" => "Verifique se o conteúdo está em Base64 válido"
            ];
        }

        $existingDisks = [];
        $disks = $this->disks;

        // Verifica onde o arquivo já existe
        foreach ($disks as $disk) {
            $absolutePath = rtrim($disk, "/") . "/" . ltrim($filePath, "/");
            if (file_exists($absolutePath)) {
                $existingDisks[] = $disk;
            }
        }

        if (!empty($existingDisks)) {
            // Substitui o arquivo em todos os discos onde já existe
            foreach ($existingDisks as $disk) {
                $absolutePath = rtrim($disk, "/") . "/" . ltrim($filePath, "/");
                file_put_contents($absolutePath, $decodedFile);
            }
            return [
                "status" => true,
                "message" => "Arquivo atualizado com sucesso",
                "locations" => $existingDisks
            ];
        }

        // Caso o arquivo não exista, segue a lógica normal
        $bestDisks = $this->getBestsDisks($fileSize, $priority);
        if (count($bestDisks) < ($this->failoverTolerance + 1)) {
            return [
                "status" => false,
                "message" => "Não há discos suficientes com espaço disponível para garantir redundância",
                "details" => "Tente novamente com um arquivo menor ou adicione mais discos"
            ];
        }

        $selectedDisks = array_slice($bestDisks, 0, $this->failoverTolerance + 1);
        foreach ($selectedDisks as $diskData) {
            $disk = $diskData["disk"];
            $absolutePath = rtrim($disk, "/") . "/" . ltrim($filePath, "/");
            $directory = dirname($absolutePath);
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($absolutePath, $decodedFile);
        }

        return [
            "status" => true,
            "message" => "Arquivo salvo com sucesso",
            "locations" => array_column($selectedDisks, "disk")
        ];
    }

    /**
     * Retorna o conteúdo de um arquivo em Base64
     * - Se o arquivo não for encontrado, retorna uma mensagem de erro
     * @param string $filePath Caminho do arquivo
     * @return array Status da operação
     */
    public function getFileBase64(string $filePath): array
    {
        $disks = $this->disks;
        $availableDisks = [];

        foreach ($disks as $disk) {
            $absolutePath = rtrim($disk, "/") . "/" . ltrim($filePath, "/");
            if (file_exists($absolutePath)) {
                $availableDisks[] = $absolutePath;
            }
        }

        if (empty($availableDisks)) {
            return [
                "status" => false,
                "message" => "Arquivo não encontrado",
                "details" => ["filename" => basename($filePath)]
            ];
        }

        $selectedPath = $availableDisks[array_rand($availableDisks)]; // Escolhe um disco aleatoriamente

        $inputStream = fopen($selectedPath, "rb");
        if (!$inputStream) {
            return [
                "status" => false,
                "message" => "Erro ao abrir o arquivo"
            ];
        }

        $outputStream = fopen("php://temp", "wb+"); // Usa a memória RAM para leitura rápida

        // **Lê e transfere os dados de forma eficiente**
        stream_copy_to_stream($inputStream, $outputStream);
        rewind($outputStream); // Volta ao início da memória para leitura

        $fileContent = stream_get_contents($outputStream);
        fclose($inputStream);
        fclose($outputStream);

        $base64Content = base64_encode($fileContent);

        return [
            "status" => true,
            "message" => "Arquivo encontrado",
            "details" => [
                "filename" => basename($filePath),
                "mime_type" => mime_content_type($selectedPath),
                "base64_full" => "data:" . mime_content_type($selectedPath) . ";base64," . $base64Content
            ]
        ];
    }

    /**
     * Exclui um arquivo de todos os discos
     * - Se o arquivo não for encontrado, retorna uma mensagem de erro
     * - Se o arquivo não puder ser excluído, retorna uma mensagem de erro
     * @param string $filePath Caminho do arquivo
     * @return array Status da operação
     */
    public function deleteFile(string $filePath): array
    {
        $disks = $this->disks;
        $found = false;

        foreach ($disks as $disk) {
            $absolutePath = rtrim($disk, "/") . "/" . ltrim($filePath, "/");

            if (file_exists($absolutePath)) {
                if (unlink($absolutePath)) {
                    $found = true;
                } else {
                    return [
                        "status" => false,
                        "message" => "Erro ao excluir o arquivo em $absolutePath"
                    ];
                }
            }
        }

        if ($found) {
            return [
                "status" => true,
                "message" => "Arquivo excluído com sucesso"
            ];
        }

        return [
            "status" => false,
            "message" => "Arquivo não encontrado"
        ];
    }

    /**
     * Move um arquivo para a lixeira
     * - Se o arquivo não for encontrado, retorna uma mensagem de erro
     * - Se o arquivo não puder ser movido, retorna uma mensagem de erro
     * @param string $filePath Caminho do arquivo
     * @return array Status da operação
     */
    public function moveToTrash(string $filePathInStorage, string $storagePath = "", string $trashPath = ""): array
    {
        $fileStorage = ($storagePath ?? $this->storagePath) . "/" . $filePathInStorage;
        $fileTrash = ($trashPath ?? $this->trashPath) . "/" . $filePathInStorage;

        $file = $this->getFileBase64($fileStorage);

        if (!$file["status"]) {
            return $file;
        }

        // Salva uma cópia do arquivo na lixeira
        $saveResult = $this->setFileBase64($fileTrash, $this->getFileBase64($fileStorage)["details"]["base64_full"], "hdd");

        if ($saveResult["status"]) {
            // Depois que a cópia for feita, exclui o original
            return $this->deleteFile($fileStorage);
        }

        return [
            "status" => false,
            "message" => "Erro ao mover para a lixeira"
        ];
    }

    /**
     * Limpa arquivos da lixeira em todos os sistemas dentro de cada disco que estão há mais de X dias.
     *
     * @param int $days Número de dias antes da exclusão
     * @return array Status da operação
     */
    public function cleanTrash(int $days = 30)
    {
        $threshold = time() - ($days * 86400); // Converte dias para segundos
        $deletedFiles = [];

        foreach ($this->disks as $disk) {
            $systemFolders = glob(rtrim($disk, "/") . "/*", GLOB_ONLYDIR); // Lista todas as pastas de sistema

            foreach ($systemFolders as $systemFolder) {
                $trashPath = $systemFolder . "/" . $this->path->trash;

                if (is_dir($trashPath)) {
                    // **Usando iterador recursivo para percorrer todas as pastas e arquivos dentro da lixeira**
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($trashPath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );

                    foreach ($iterator as $file) {
                        if ($file->isFile() && $file->getMTime() < $threshold) {
                            if (unlink($file->getPathname())) {
                                $deletedFiles[] = $file->getPathname();
                            }
                        }
                    }
                }
            }
        }

        return [
            "status" => true,
            "message" => count($deletedFiles) . " arquivos removidos da lixeira",
            "deleted_files" => $deletedFiles
        ];
    }

    /**
     * Verifica e corrige a redundância de arquivos no storage.
     * - Se um arquivo tiver menos cópias do que o necessário, cria novas cópias nos melhores discos.
     * - Se um arquivo tiver mais cópias do que o necessário, remove cópias extras.
     *
     * @return array Status da operação
     */
    public function verifyAndFixStorage(): array
    {
        $disks = $this->disks;
        $requiredCopies = $this->failoverTolerance + 1;

        // Se a tolerância for maior ou igual ao número de discos, não tem como garantir redundância
        if ($requiredCopies > count($disks)) {
            return [
                "status" => false,
                "message" => "Tolerância de failover muito alta. Reduza o valor ou adicione mais discos."
            ];
        }

        $fileLocations = [];

        // Mapeia onde cada arquivo está
        foreach ($disks as $disk) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($disk, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $relativePath = str_replace($disk . "/", "", $file->getPathname());
                    $fileLocations[$relativePath][] = $disk;
                }
            }
        }

        $fixes = [
            "files_replicated" => 0,
            "files_removed" => 0
        ];

        // Ajusta arquivos que estão em menos ou mais discos do que o necessário
        foreach ($fileLocations as $file => $locations) {
            $currentCopies = count($locations);

            if ($currentCopies < $requiredCopies) {
                // Arquivo tem menos cópias do que o necessário → Criar novas cópias
                $sourceDisk = $locations[0];
                $sourcePath = $sourceDisk . "/" . $file;
                $fileSize = filesize($sourcePath); // Obtém o tamanho do arquivo

                $targetDisks = array_diff($disks, $locations);
                $bestDisks = $this->getBestsDisks($fileSize, "hdd"); // Usa getBestsDisks() para escolher

                foreach ($bestDisks as $diskData) {
                    $newDisk = $diskData["disk"];
                    if (!in_array($newDisk, $locations) && count($locations) < $requiredCopies) {
                        $targetPath = $newDisk . "/" . $file;

                        if (!is_dir(dirname($targetPath))) {
                            mkdir(dirname($targetPath), 0777, true);
                        }

                        copy($sourcePath, $targetPath);
                        $locations[] = $newDisk;
                        $fixes["files_replicated"]++;
                    }
                }
            } elseif ($currentCopies > $requiredCopies) {
                // Arquivo tem mais cópias do que o necessário → Remover cópias extras
                while (count($locations) > $requiredCopies) {
                    $diskToRemove = array_pop($locations);
                    unlink($diskToRemove . "/" . $file);
                    $fixes["files_removed"]++;
                }
            }
        }

        return [
            "status" => true,
            "message" => "Verificação concluída.",
            "details" => $fixes
        ];
    }
}
