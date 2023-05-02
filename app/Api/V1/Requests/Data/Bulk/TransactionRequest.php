<?php

/*
 * TransactionRequest.php
 * Copyright (c) 2021 james@firefly-iii.org
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Api\V1\Requests\Data\Bulk;

use FireflyIII\Enums\ClauseType;
use FireflyIII\Rules\IsValidBulkClause;
use FireflyIII\Support\Request\ChecksLogin;
use FireflyIII\Support\Request\ConvertsDataTypes;
use FireflyIII\Validation\Api\Data\Bulk\ValidatesBulkTransactionQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;
use Illuminate\Support\Facades\Log;

/**
 * Class TransactionRequest
 */
class TransactionRequest extends FormRequest
{
    use ChecksLogin;
    use ConvertsDataTypes;
    use ValidatesBulkTransactionQuery;

    /**
     * @return array
     */
    public function getAll(): array
    {
        $data = [];
        try {
            $data = [
                'query' => json_decode($this->get('query'), true, 8, JSON_THROW_ON_ERROR),
            ];
        } catch (JsonException $e) {
            // dont really care. the validation should catch invalid json.
            Log::error($e->getMessage());
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            'query' => ['required', 'min:1', 'max:255', 'json', new IsValidBulkClause(ClauseType::TRANSACTION)],
        ];
    }

    /**
     * @param  Validator  $validator
     *
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(
            function (Validator $validator) {
                // validate transaction query data.
                $this->validateTransactionQuery($validator);
            }
        );
    }
}
