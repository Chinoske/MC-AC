<?php
/**
 * Validation — Validación de campos de formularios
 */
class Validation
{
    private bool  $passed = false;
    private array $errors = [];

    public function check(array $source, array $rules): self
    {
        $this->errors = [];

        foreach ($rules as $item => $itemRules) {
            $value = $source[$item] ?? '';

            foreach ($itemRules as $ruleName => $ruleValue) {
                switch ($ruleName) {
                    case 'required':
                        if ($ruleValue && trim((string) $value) === '') {
                            $this->addError($item, 'El campo es requerido.');
                        }
                        break;

                    case 'min':
                        if (mb_strlen((string) $value, 'UTF-8') < (int) $ruleValue) {
                            $this->addError($item, "Mínimo {$ruleValue} caracteres.");
                        }
                        break;

                    case 'max':
                        if (mb_strlen((string) $value, 'UTF-8') > (int) $ruleValue) {
                            $this->addError($item, "Máximo {$ruleValue} caracteres.");
                        }
                        break;

                    case 'matches':
                        if ($value !== ($source[$ruleValue] ?? '')) {
                            $this->addError($item, 'Los campos no coinciden.');
                        }
                        break;

                    case 'unique':
                        // $ruleValue: ['table' => '...', 'field' => '...', 'db' => 'auth'|'chars', 'realm' => 1]
                        $realmId = (int) ($ruleValue['realm'] ?? 1);
                        $db = ($ruleValue['db'] === 'chars') ? DB::chars($realmId) : DB::auth();
                        $tbl   = $ruleValue['table'];
                        $field = $ruleValue['field'];
                        $n = $db->count(
                            "SELECT COUNT(*) FROM `{$tbl}` WHERE `{$field}` = ?",
                            [$value]
                        );
                        if ($n > 0) {
                            $this->addError($item, 'El valor ya existe.');
                        }
                        break;

                    case 'regex':
                        if (!preg_match($ruleValue, (string) $value)) {
                            $this->addError($item, 'Formato inválido.');
                        }
                        break;
                }
            }
        }

        $this->passed = empty($this->errors);
        return $this;
    }

    public function passed(): bool  { return $this->passed; }
    public function errors(): array { return $this->errors; }

    public function firstError(): string
    {
        return array_values($this->errors)[0] ?? '';
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }
}
