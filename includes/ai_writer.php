<?php
/**
 * includes/ai_writer.php
 * ─────────────────────────────────────────────────────────────────────────
 * Couche d'abstraction pour la rédaction assistée par IA (SWAPPABLE).
 *
 * Fournisseur par défaut : Groq (gratuit, rapide, API compatible OpenAI).
 * Changer de fournisseur = définir les constantes MDT_AI_* dans
 * config/config.local.php (chargé avant tout le reste). Aucune autre
 * modification de code n'est nécessaire.
 *
 * Fournisseurs supportés :
 *   - 'openai'    : toute API compatible OpenAI (Groq, Mistral, OpenRouter,
 *                   Google Gemini via son endpoint /openai/, OpenAI, Ollama…).
 *                   → MDT_AI_BASE_URL + MDT_AI_MODEL + MDT_AI_API_KEY.
 *   - 'anthropic' : API Anthropic Messages (réutilise ANTHROPIC_API_KEY déjà
 *                   présente dans config.php si MDT_AI_API_KEY n'est pas défini).
 *
 * Exemple (config/config.local.php) pour Groq :
 *   define('MDT_AI_API_KEY', 'gsk_xxxxxxxxxxxxxxxxx');
 * Pour repasser sur Claude (déjà configuré) :
 *   define('MDT_AI_PROVIDER', 'anthropic');
 *
 * API :
 *   $res = mdt_ai_complete($systemPrompt, $userPrompt, ['max_tokens'=>1200,'temperature'=>0.4]);
 *   // $res = ['ok'=>bool, 'text'=>string, 'error'=>string]
 */

if (!defined('MDT_AI_PROVIDER')) define('MDT_AI_PROVIDER', 'openai');                       // 'openai' | 'anthropic'
if (!defined('MDT_AI_BASE_URL')) define('MDT_AI_BASE_URL', 'https://api.groq.com/openai/v1'); // provider 'openai'
if (!defined('MDT_AI_MODEL'))    define('MDT_AI_MODEL', 'llama-3.3-70b-versatile');          // modèle par défaut (Groq)
if (!defined('MDT_AI_API_KEY'))  define('MDT_AI_API_KEY', '');                                // À DÉFINIR dans config.local.php
if (!defined('MDT_AI_TIMEOUT'))  define('MDT_AI_TIMEOUT', 45);                                // secondes

if (!function_exists('mdt_ai_is_configured')) {
    /** L'IA est-elle utilisable (clé disponible pour le fournisseur choisi) ? */
    function mdt_ai_is_configured(): bool
    {
        if (MDT_AI_API_KEY !== '') return true;
        // Repli Anthropic sur la clé historique du projet.
        return strtolower((string) MDT_AI_PROVIDER) === 'anthropic'
            && defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '';
    }

    /** Requête HTTP POST JSON bas niveau. Retourne [httpCode:int, body:string, error:string]. */
    function mdt_ai_http(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'cURL indisponible sur le serveur'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int) MDT_AI_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || $resp === false) {
            return [0, '', "Service IA injoignable (cURL #$errno)"];
        }
        return [$code, (string) $resp, ''];
    }

    /** Message d'erreur lisible à partir d'une réponse d'API. */
    function mdt_ai_error_message($decoded, int $code): string
    {
        if (is_array($decoded)) {
            if (isset($decoded['error']['message'])) return 'IA (' . $code . ') : ' . substr((string) $decoded['error']['message'], 0, 200);
            if (isset($decoded['error']) && is_string($decoded['error'])) return 'IA (' . $code . ') : ' . substr($decoded['error'], 0, 200);
            if (isset($decoded['message']) && is_string($decoded['message'])) return 'IA (' . $code . ') : ' . substr($decoded['message'], 0, 200);
        }
        return 'Service IA : erreur HTTP ' . $code;
    }

    /**
     * Complétion de texte via le fournisseur configuré.
     * @return array{ok:bool,text:string,error:string}
     */
    function mdt_ai_complete(string $system, string $user, array $opts = []): array
    {
        $provider  = strtolower((string) MDT_AI_PROVIDER);
        $maxTokens = max(64, min((int) ($opts['max_tokens'] ?? 1200), 4096));
        $temp      = isset($opts['temperature']) ? (float) $opts['temperature'] : 0.4;

        if ($provider === 'anthropic') {
            $key = MDT_AI_API_KEY !== '' ? MDT_AI_API_KEY : (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '');
            if ($key === '') return ['ok' => false, 'text' => '', 'error' => 'Clé API IA non configurée'];
            $model = (function_exists('str_starts_with') && str_starts_with((string) MDT_AI_MODEL, 'claude'))
                ? MDT_AI_MODEL : 'claude-sonnet-4-20250514';
            $body = json_encode([
                'model'       => $model,
                'max_tokens'  => $maxTokens,
                'temperature' => $temp,
                'system'      => $system,
                'messages'    => [['role' => 'user', 'content' => $user]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            [$code, $resp, $err] = mdt_ai_http('https://api.anthropic.com/v1/messages', $body, [
                'Content-Type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
            ]);
            if ($err !== '') return ['ok' => false, 'text' => '', 'error' => $err];
            $j = json_decode($resp, true);
            if ($code !== 200 || !is_array($j)) return ['ok' => false, 'text' => '', 'error' => mdt_ai_error_message($j, $code)];
            $text = '';
            foreach (($j['content'] ?? []) as $blk) {
                if (is_array($blk) && ($blk['type'] ?? '') === 'text') $text .= $blk['text'] ?? '';
            }
            $text = trim($text);
            return $text !== '' ? ['ok' => true, 'text' => $text, 'error' => ''] : ['ok' => false, 'text' => '', 'error' => 'Réponse IA vide'];
        }

        // ── Provider 'openai' (Groq par défaut, et tout endpoint compatible) ──
        $key = MDT_AI_API_KEY;
        if ($key === '') return ['ok' => false, 'text' => '', 'error' => 'Clé API IA non configurée'];
        $base = rtrim((string) MDT_AI_BASE_URL, '/');
        $body = json_encode([
            'model'       => MDT_AI_MODEL,
            'temperature' => $temp,
            'max_tokens'  => $maxTokens,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$code, $resp, $err] = mdt_ai_http($base . '/chat/completions', $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ]);
        if ($err !== '') return ['ok' => false, 'text' => '', 'error' => $err];
        $j = json_decode($resp, true);
        if ($code !== 200 || !is_array($j)) return ['ok' => false, 'text' => '', 'error' => mdt_ai_error_message($j, $code)];
        $text = trim((string) ($j['choices'][0]['message']['content'] ?? ''));
        return $text !== '' ? ['ok' => true, 'text' => $text, 'error' => ''] : ['ok' => false, 'text' => '', 'error' => 'Réponse IA vide'];
    }
}
