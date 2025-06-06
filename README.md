# BifrostPHP-Storage

BifrostPHP-Storage é um sistema de armazenamento distribuído entre diversos discos. Ele é fornecido pronto para uso em containers Docker e permite salvar arquivos via HTTP de forma redundante, garantindo tolerância a falhas.

## Características

- Replica arquivos em múltiplos discos respeitando o valor de `FAILOVER_TOLERANCE`.
- Processos de manutenção e tarefas em segundo plano executados por um *worker* utilizando Redis.
- Autenticação simples por token via cabeçalho `Authorization`.
- Todo o serviço é disponibilizado em um único container com Apache e PHP.

## Variáveis de Ambiente

| Variável            | Descrição                                                      | Padrão         |
|---------------------|-----------------------------------------------------------------|---------------|
| `FAILOVER_TOLERANCE`| Quantidade extra de cópias que devem existir de cada arquivo.    | `0`           |
| `DIR_DISKS`         | Pasta que contém os diretórios representando cada disco físico. | `/disks`      |
| `DIR_STORAGE`       | Nome da pasta interna usada para armazenar os arquivos.         | `/storage`    |
| `DIR_TRASH`         | Pasta para onde arquivos excluídos são movidos.                   | `/trash`      |
| `DIR_LOGS`          | Diretório destinado aos logs gerados pelo sistema.              | `/logs`       |
| `REDIS_HOST`        | Endereço do servidor Redis utilizado para fila de tarefas.       | `redis`       |
| `REDIS_PORT`        | Porta do Redis.                                                  | `6379`        |
| `AUTH_*`            | Tokens de autenticação para cada sistema. Ex.: `AUTH_APP=token`. | -             |

Tokens de autenticação são definidos como variáveis que iniciam com `AUTH_`. O texto após o prefixo será considerado o nome do projeto.

## Como Usar

1. Crie as pastas que representarão seus discos (ex.: `disks/ssd_disk1`, `disks/hdd_disk2`, ...).
2. Ajuste as variáveis de ambiente do `docker-compose.yml` conforme sua necessidade, adicionando seus tokens `AUTH_*` e montando os discos criados.
3. Inicialize os containers:
   ```bash
   docker-compose up -d
   ```
4. A API ficará disponível em `http://localhost:82`.

### Exemplo de `docker-compose.yml`

```yaml
services:
  redis:
    image: redis:alpine
    container_name: redis
    restart: always
    networks:
      - bifrost-net-storage

  bifrost-storage:
    image: ghcr.io/felipe-cavalca/bifrostphp-storage:latest
    container_name: bifrost-storage
    restart: always
    ports:
      - "82:80"
    environment:
      - FAILOVER_TOLERANCE=1
      - DIR_DISKS=/disks
      - AUTH_APP=a1b2c3d4
      - REDIS_HOST=redis
      - REDIS_PORT=6379
    volumes:
      - ./disks/ssd_disk1:/disks/ssd_disk1
    networks:
      - bifrost-net-storage

networks:
  bifrost-net-storage:
    driver: bridge
```

## Autenticação

Todas as requisições precisam do cabeçalho `Authorization` para identificar o sistema:

```http
Authorization: Bearer <token>
```

## Exemplos de Requisição

### Enviar um arquivo (Base64)

```bash
curl -X POST http://localhost:82/pasta/arquivo.txt \
     -H "Authorization: Bearer <token>" \
     -H "Content-Type: application/json" \
     -d '{"base64Content": "c3VhIGV4ZW1wbG8gZW0gYmFzZTY0"}'
```

### Baixar um arquivo

```bash
curl -X GET http://localhost:82/pasta/arquivo.txt \
     -H "Authorization: Bearer <token>"
```

### Excluir um arquivo

```bash
curl -X DELETE http://localhost:82/pasta/arquivo.txt \
     -H "Authorization: Bearer <token>"
```

## Otimizações de Desempenho

Esta versão utiliza streams para codificação e decodificação Base64 durante a
leitura e escrita dos arquivos. Isso reduz o uso de memória e acelera o acesso
ao disco, principalmente para arquivos grandes.

## Licença

Distribuído sob a [MIT License](LICENSE).
