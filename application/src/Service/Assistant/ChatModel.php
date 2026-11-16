<?php

declare(strict_types=1);

namespace App\Service\Assistant;

/**
 * Ein angesprochenes Sprachmodell.
 *
 * Anthropic und die OpenAI-kompatiblen Anbieter beschreiben Werkzeuge und
 * Gesprächsverläufe unterschiedlich. Diese Schnittstelle legt ein neutrales
 * Format fest; die Übersetzung in das jeweilige Wire-Format ist Sache der
 * Umsetzung, sodass der Assistent selbst keinen Anbieter kennt.
 *
 * Nachrichten haben eine dieser drei Formen:
 *
 *   ['role' => 'user',      'text' => string]
 *   ['role' => 'assistant', 'text' => string, 'toolCalls' => list<ToolCall>, 'raw' => mixed]
 *   ['role' => 'tool',      'results'  => list<array{id: string, text: string}>]
 *
 * Ein ToolCall ist ['id' => string, 'name' => string, 'input' => array].
 * Das Feld 'raw' gibt die Umsetzung selbst zurück (siehe ModelTurn) und
 * bekommt sie unverändert wieder – dort darf stehen, was sie zum Fortsetzen
 * des Gesprächs in ihrem eigenen Format braucht.
 *
 * Mit $beiText streamt die Umsetzung: Jedes Stück Antworttext geht an den
 * Rückruf, sobald es da ist – der Assistent zeigt es so, während das Modell
 * noch schreibt. Der fertige Zug kommt trotzdem vollständig zurück.
 */
interface ChatModel
{
    /**
     * @param list<array{name: string, description: string, schema: array<string, mixed>}> $tools
     * @param list<array<string, mixed>>                                                   $messages
     * @param (\Closure(string): void)|null                                                 $beiText
     *
     * @throws AssistantUnavailable wenn der Anbieter nicht erreichbar ist oder ablehnt
     */
    public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn;
}
