<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DroneModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A pilot's choice of airframe for one mission.
 *
 * The drone is named by uuid, which is what {@see \App\Http\Resources\DroneModelResource}
 * sends and the only identifier the client is given. Existence is checked
 * here so an unknown uuid is a 422 naming the field rather than a 404 from
 * route model binding, which on this endpoint would read as "the mission
 * does not exist".
 */
final class UpdateMissionDroneRequest extends FormRequest
{
    /**
     * Not named `drone`: a Request's `__get` reads input, so a property
     * sharing a field's name would answer with the raw uuid string.
     */
    private ?DroneModel $resolvedDrone = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * The entitlement is the `can:drone_config_editor` middleware on the
     * route, and the mission's own plan check is in the controller alongside
     * the one every other challenge endpoint makes. Nothing is left for this
     * to decide.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'drone' => ['required', 'string', Rule::exists(DroneModel::class, 'uuid')],
        ];
    }

    /**
     * The chosen airframe.
     *
     * Memoised because the controller reads it twice — once to hand to the
     * action, once to name in the toast — and the row cannot change between
     * those two reads inside one request. Resolving it here rather than
     * through route model binding is what buys the 422 the docblock above
     * describes; memoising is what keeps that worth one query instead of one
     * per caller.
     */
    public function drone(): DroneModel
    {
        return $this->resolvedDrone ??= DroneModel::query()
            ->where('uuid', $this->safe()->string('drone')->value())
            ->firstOrFail();
    }
}
