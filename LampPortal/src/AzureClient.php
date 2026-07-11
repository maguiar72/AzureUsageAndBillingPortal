<?php

declare(strict_types=1);

/**
 * Cliente da Azure Resource Manager / Cost Management API.
 *
 * Equivalente moderno ao Commons/AzureResourceManagerUtil.cs do projeto
 * original. Usa o fluxo OAuth2 "client credentials" (service principal)
 * e a Cost Management Query API, que substitui a antiga e ja descontinuada
 * API Commerce/UsageAggregates + RateCard usada pelos WebJobs.
 *
 * Docs: https://learn.microsoft.com/rest/api/cost-management/query/usage
 */
class AzureClient
{
    private array $cfg;
    /** Cache de tokens por tenant. */
    private array $tokenCache = [];

    public function __construct(array $azureConfig)
    {
        $this->cfg = $azureConfig;
    }

    /**
     * Obtem um access token para chamar o Azure Resource Manager.
     *
     * Suporta dois metodos (config 'auth_method'):
     *   - 'managed_identity' (recomendado na Azure): usa a Managed Identity
     *     do Container App / App Service / VM. Sem segredos.
     *   - 'client_secret': fluxo OAuth2 client credentials (service principal).
     *
     * Faz cache em memoria durante a execucao.
     */
    public function getAccessToken(string $tenantId): string
    {
        $method = $this->cfg['auth_method'] ?? 'client_secret';

        if ($method === 'managed_identity') {
            return $this->getManagedIdentityToken();
        }

        if (isset($this->tokenCache[$tenantId])) {
            return $this->tokenCache[$tenantId];
        }

        $url = rtrim($this->cfg['login_url'], '/') . "/{$tenantId}/oauth2/v2.0/token";
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'scope'         => $this->cfg['scope'],
        ]);

        [$status, $resp] = $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if ($status !== 200) {
            throw new RuntimeException(
                "Falha ao obter token da Azure (HTTP {$status}): " . $this->extractError($resp)
            );
        }

        $data = json_decode($resp, true);
        if (!isset($data['access_token'])) {
            throw new RuntimeException('Resposta de token invalida da Azure.');
        }

        return $this->tokenCache[$tenantId] = $data['access_token'];
    }

    /**
     * Obtem um token via Managed Identity.
     *
     * - Em Container Apps / App Service: usa IDENTITY_ENDPOINT + IDENTITY_HEADER
     *   (api-version 2019-08-01, header X-IDENTITY-HEADER).
     * - Em VM / IMDS: fallback para 169.254.169.254 (header Metadata: true).
     *
     * Para identidade atribuida pelo usuario (user-assigned), informe o
     * client_id da identidade em config 'client_id'.
     */
    private function getManagedIdentityToken(): string
    {
        if (isset($this->tokenCache['__mi__'])) {
            return $this->tokenCache['__mi__'];
        }

        $resource = rtrim($this->cfg['management_url'], '/'); // https://management.azure.com
        $clientId = $this->cfg['client_id'] ?? '';

        $identityEndpoint = getenv('IDENTITY_ENDPOINT') ?: '';
        $identityHeader   = getenv('IDENTITY_HEADER') ?: '';

        if ($identityEndpoint !== '' && $identityHeader !== '') {
            // Container Apps / App Service
            $url = $identityEndpoint
                 . (strpos($identityEndpoint, '?') === false ? '?' : '&')
                 . 'resource=' . rawurlencode($resource)
                 . '&api-version=2019-08-01';
            if ($clientId !== '') {
                $url .= '&client_id=' . rawurlencode($clientId);
            }
            $headers = ['X-IDENTITY-HEADER: ' . $identityHeader];
        } else {
            // IMDS (VM / IaaS)
            $url = 'http://169.254.169.254/metadata/identity/oauth2/token'
                 . '?resource=' . rawurlencode($resource)
                 . '&api-version=2018-02-01';
            if ($clientId !== '') {
                $url .= '&client_id=' . rawurlencode($clientId);
            }
            $headers = ['Metadata: true'];
        }

        [$status, $resp] = $this->httpRequest('GET', $url, null, $headers);

        if ($status !== 200) {
            throw new RuntimeException(
                "Falha ao obter token via Managed Identity (HTTP {$status}): " . $this->extractError($resp)
            );
        }

        $data = json_decode($resp, true);
        if (!isset($data['access_token'])) {
            throw new RuntimeException('Resposta de token (Managed Identity) invalida.');
        }

        return $this->tokenCache['__mi__'] = $data['access_token'];
    }

    /**
     * Consulta os custos (ActualCost) de uma subscription num intervalo,
     * detalhados por RECURSO x DIA.
     *
     * A Query API permite no maximo 2 agrupamentos; usamos ResourceId +
     * ServiceName. Do ResourceId derivamos o resource group, o nome e o
     * tipo do recurso.
     *
     * Retorna um array de linhas normalizadas:
     *   [ 'usage_date', 'resource_id', 'resource_name', 'resource_group',
     *     'resource_type', 'service_name', 'cost', 'currency' ]
     */
    public function queryUsage(string $subscriptionId, string $tenantId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $token = $this->getAccessToken($tenantId);

        $url = sprintf(
            '%s/subscriptions/%s/providers/Microsoft.CostManagement/query?api-version=%s',
            rtrim($this->cfg['management_url'], '/'),
            $subscriptionId,
            $this->cfg['api_version']
        );

        $payload = [
            'type'      => 'ActualCost',
            'timeframe' => 'Custom',
            'timePeriod' => [
                'from' => $from->format('Y-m-d\T00:00:00\Z'),
                'to'   => $to->format('Y-m-d\T23:59:59\Z'),
            ],
            'dataset' => [
                'granularity' => 'Daily',
                'aggregation' => [
                    'totalCost' => ['name' => 'Cost', 'function' => 'Sum'],
                ],
                // Maximo 2 agrupamentos permitidos pela Query API.
                'grouping' => [
                    ['type' => 'Dimension', 'name' => 'ResourceId'],
                    ['type' => 'Dimension', 'name' => 'ServiceName'],
                ],
            ],
        ];

        $rows = [];
        $requestUrl = $url;
        $body = json_encode($payload);
        $guard = 0;

        // A API pagina via properties.nextLink.
        do {
            [$status, $resp] = $this->httpRequest('POST', $requestUrl, $body, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);

            if ($status !== 200) {
                throw new RuntimeException(
                    "Erro na Cost Management API para {$subscriptionId} (HTTP {$status}): "
                    . $this->extractError($resp)
                );
            }

            $data = json_decode($resp, true);
            $props = $data['properties'] ?? [];
            $columns = $props['columns'] ?? [];
            $dataRows = $props['rows'] ?? [];

            $idx = $this->columnIndex($columns);
            foreach ($dataRows as $r) {
                $rows[] = $this->normalizeRow($r, $idx);
            }

            $requestUrl = $props['nextLink'] ?? null;
            // Em paginas subsequentes o corpo ja nao e necessario.
            $body = null;
            $guard++;
        } while ($requestUrl && $guard < 100);

        return $rows;
    }

    /** Mapeia nomes de coluna -> indice, de forma tolerante a variacoes. */
    private function columnIndex(array $columns): array
    {
        $idx = [];
        foreach ($columns as $i => $col) {
            $name = strtolower($col['name'] ?? '');
            $idx[$name] = $i;
        }
        return $idx;
    }

    private function normalizeRow(array $row, array $idx): array
    {
        $get = function (string $key, $default = null) use ($row, $idx) {
            return isset($idx[$key]) && array_key_exists($idx[$key], $row)
                ? $row[$idx[$key]]
                : $default;
        };

        // UsageDate vem como inteiro AAAAMMDD.
        $rawDate = (string)$get('usagedate', '');
        $date = strlen($rawDate) === 8
            ? substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2)
            : date('Y-m-d');

        $resourceId = (string)($get('resourceid') ?: '');
        [$name, $group, $type] = $this->parseResourceId($resourceId);

        return [
            'usage_date'     => $date,
            'resource_id'    => $resourceId,
            'resource_name'  => $name,
            'resource_group' => $group,
            'resource_type'  => $type,
            'service_name'   => (string)($get('servicename') ?: 'Unknown'),
            'cost'           => (float)($get('cost', 0)),
            'currency'       => (string)($get('currency') ?: ($this->cfg['currency'] ?? 'USD')),
        ];
    }

    /**
     * Extrai (nome, resource group, tipo) de um ResourceId ARM, ex.:
     *   /subscriptions/{s}/resourceGroups/{rg}/providers/{ns}/{tipo}/{nome}
     * Tolerante a maiusculas/minusculas e a IDs vazios (custos sem recurso,
     * como marketplace/reservas).
     */
    private function parseResourceId(string $rid): array
    {
        $name = '(sem recurso)';
        $group = '(sem grupo)';
        $type = '';

        if ($rid !== '') {
            if (preg_match('#/resourcegroups/([^/]+)#i', $rid, $m)) {
                $group = $m[1];
            }
            if (preg_match('#/providers/([^/]+/[^/]+)#i', $rid, $m)) {
                $type = $m[1];
            }
            $parts = array_values(array_filter(explode('/', $rid), 'strlen'));
            if ($parts) {
                $name = end($parts);
            }
        }

        return [
            mb_substr($name, 0, 255),
            mb_substr($group, 0, 255),
            mb_substr($type, 0, 255),
        ];
    }

    /**
     * Obtem o nome amigavel (displayName) de uma subscription via ARM.
     * Requer permissao de leitura na subscription; em caso de falha
     * (ex.: 403), retorna string vazia para o chamador usar um fallback.
     */
    public function getSubscriptionName(string $subscriptionId, string $tenantId): string
    {
        try {
            $token = $this->getAccessToken($tenantId);
            $url = sprintf(
                '%s/subscriptions/%s?api-version=2020-01-01',
                rtrim($this->cfg['management_url'], '/'),
                $subscriptionId
            );
            [$status, $resp] = $this->httpRequest('GET', $url, null, [
                'Authorization: Bearer ' . $token,
            ]);
            if ($status === 200) {
                $data = json_decode($resp, true);
                return (string)($data['displayName'] ?? '');
            }
        } catch (Throwable $e) {
            // ignora - fallback no chamador
        }
        return '';
    }

    /** Extrai mensagem de erro legivel de uma resposta JSON da Azure. */
    private function extractError(string $resp): string
    {
        $data = json_decode($resp, true);
        if (isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        if (isset($data['error_description'])) {
            return $data['error_description'];
        }
        return substr($resp, 0, 500);
    }

    /**
     * Executa uma requisicao HTTP via cURL.
     * @return array{0:int,1:string} [statusCode, responseBody]
     */
    private function httpRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Erro de rede ao chamar a Azure: {$err}");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, (string)$resp];
    }
}
