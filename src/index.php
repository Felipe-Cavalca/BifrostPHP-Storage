<?php

class index
{
    private int $failoverTolerance = 1;
    private object $path;

    public function __construct()
    {
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
        header("Content-Type: application/json");
        return $this->handleResponse($this->runScript());
    }

    public function __get($var)
    {
        switch ($var) {
            case "disks":
                return $this->getAvailableDisks();
            case "failoverTolerance":
                return getenv("FAILOVER_TOLERANCE") ?: $this->failoverTolerance;
            default:
                return $this->$var;
        }
    }

    private function handleResponse(mixed $return): string
    {
        if (is_array($return)) {
            return json_encode($return);
        } else {
            return (string) $return;
        }
    }

    private function runScript(): mixed
    {
        try {
            switch ($_SERVER["REQUEST_METHOD"]) {
                case "GET":
                    return $this->getFileBase64($_GET["file"]);
                case "POST":
                    $data = json_decode(file_get_contents("php://input"), true);
                    return $this->setFileBase64($_GET["file"], $data["base64Content"]);
                case "DELETE":
                    return $this->deleteFile($_GET["file"]);
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
     *
     * @param int|null $fileSize Tamanho do arquivo em bytes (opcional)
     * @return array Lista de discos ordenados do melhor para o pior
     */
    private function getBestsDisks(int|null $fileSize = null): array
    {
        $disks = $this->disks;
        $highPriority = [];
        $lowPriority = [];

        foreach ($disks as $disk) {
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
     * Verifica se o caminho passado é válido para armazenamento
     * - O caminho deve estar dentro de um dos diretórios de armazenamento
     *
     * @param string $path Caminho a ser verificado
     * @return bool
     */
    private function pathIsValid(string $path): bool
    {
        $validPaths = [
            $this->path->disks,
            $this->path->storage,
            $this->path->trash,
            $this->path->logs
        ];

        foreach ($validPaths as $validPath) {
            if (strpos($path, $validPath) === 0) {
                return true;
            }
        }

        return false;
    }

    public function setFileBase64(string $filePath, string $base64Content): array
    {
        $fileSize = strlen(base64_decode($base64Content)); // Obtém o tamanho do arquivo em bytes

        $disks = $this->getBestsDisks($fileSize);

        if (count($disks) < ($this->failoverTolerance + 1)) {
            return [
                "status" => false,
                "message" => "Não há discos suficientes com espaço disponível para garantir redundância",
                "details" => "Tente novamente com um arquivo menor ou adicione mais discos"
            ];
        }

        // Seleciona a quantidade correta de discos conforme a tolerância de falha
        $selectedDisks = array_slice($disks, 0, $this->failoverTolerance + 1);

        // Decodifica o arquivo apenas uma vez
        $decodedFile = base64_decode($base64Content);
        if ($decodedFile === false) {
            return [
                "status" => false,
                "message" => "Falha ao decodificar Base64",
                "details" => "Verifique se o conteúdo está em Base64 válido"
            ];
        }

        foreach ($selectedDisks as $disk) {
            $absolutePath = rtrim($disk["disk"], "/") . "/" . ltrim($filePath, "/");
            $directory = dirname($absolutePath);

            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            if (file_put_contents($absolutePath, $decodedFile) === false) {
                return [
                    "status" => false,
                    "message" => "Erro ao salvar o arquivo em $absolutePath",
                    "details" => "Verifique as permissões de escrita no disco"
                ];
            }
        }

        return [
            "status" => true,
            "message" => "Arquivo salvo com sucesso",
            "locations" => array_column($selectedDisks, "disk")
        ];
    }

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

    public function moveToTrash(string $filePath): array
    {
        $trashPath = "trash/" . basename($filePath);

        // Salva uma cópia do arquivo na lixeira
        $saveResult = $this->setFileBase64($trashPath, $this->getFileBase64($filePath)["details"]["base64_full"]);

        if (!$saveResult["success"]) {
            return ["status" => false, "message" => "Erro ao mover para a lixeira"];
        }

        // Depois que a cópia for feita, exclui o original
        return $this->deleteFile($filePath);
    }

    public function verifyAndFixStorage(): array
    {
        $disks = $this->getAvailableDisks();
        $fileLocations = [];

        // Mapeia onde cada arquivo está
        foreach ($disks as $disk) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($disk, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relativePath = str_replace($disk . "/", "", $file->getPathname());
                $fileLocations[$relativePath][] = $disk;
            }
        }

        // Ajusta arquivos que estão em apenas um ou mais de dois discos
        foreach ($fileLocations as $file => $locations) {
            if (count($locations) == 1) {
                // Arquivo só existe em um disco → Copiar para outro disco
                $sourceDisk = $locations[0];
                $targetDisks = array_diff($disks, [$sourceDisk]);

                // Escolher o disco com mais espaço
                usort($targetDisks, function ($a, $b) {
                    return disk_free_space($b) <=> disk_free_space($a);
                });

                if (!empty($targetDisks)) {
                    $newDisk = $targetDisks[0];
                    $sourcePath = $sourceDisk . "/" . $file;
                    $targetPath = $newDisk . "/" . $file;

                    if (!is_dir(dirname($targetPath))) {
                        mkdir(dirname($targetPath), 0777, true);
                    }

                    copy($sourcePath, $targetPath);
                }
            } elseif (count($locations) > 2) {
                // Arquivo existe em mais de dois discos → Remover cópias extras
                while (count($locations) > 2) {
                    $diskToRemove = array_pop($locations);
                    unlink($diskToRemove . "/" . $file);
                }
            }
        }

        return ["success" => true];
    }

    public function cleanTrash()
    {
        foreach ($this->disks as $disk) {
            $trashPath = rtrim($disk, "/") . "/trash/";

            if (is_dir($trashPath)) {
                foreach (scandir($trashPath) as $file) {
                    if ($file !== "." && $file !== "..") {
                        unlink($trashPath . $file);
                    }
                }
            }
        }

        return ["status" => true, "message" => "Lixeira limpa em todos os discos"];
    }
}

echo new index();
