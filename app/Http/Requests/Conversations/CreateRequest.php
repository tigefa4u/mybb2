<?php
/**
 * Topic create request.
 *
 * @author    MyBB Group
 * @version   2.0.0
 * @package   mybb/core
 * @license   http://www.mybb.com/licenses/bsd3 BSD-3
 */

namespace MyBB\Core\Http\Requests\Conversations;

use Illuminate\Contracts\Auth\Guard;

class CreateRequest extends ParticipantRequest
{
    /**
     * @var Guard
     */
    private $guard;

    /**
     * @param Guard $guard
     */
    public function __construct(Guard $guard)
    {
        $this->guard = $guard;
    }

    /**
     * @return array
     */
    public function rules() : array
    {
        return [
            'participants' => 'required|usernameArray',
            'message'      => 'required',
            'title'        => 'required',
        ];
    }

    /**
     * @return bool
     */
    public function authorize() : bool
    {
        //return $this->guard->check();
        return true; // TODO: In dev return, needs replacing for later...
    }
}
