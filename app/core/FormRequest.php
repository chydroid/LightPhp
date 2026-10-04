<?php
declare(strict_types=1);

namespace core;

use core\exception\HttpException;
use core\exception\ValidationException;

/**
 * 表单请求基类
 *
 * 继承 Request，子类通过 rules()/authorize() 声明验证规则与授权策略。
 * 由 Router 在注入控制器方法时自动调用 validateResolved() 触发验证：
 *   - authorize() 返回 false → 抛 HttpException(403)
 *   - rules() 校验失败 → 抛 ValidationException（Application 渲染为 422）
 *
 * 用法：
 *   class StoreUserRequest extends \core\FormRequest {
 *       public function rules(): array { return ['name' => 'required', 'email' => 'required|email']; }
 *   }
 *   class UserController extends \core\Controller {
 *       public function store(StoreUserRequest $req): \core\Response {
 *           return $this->json($req->validated());
 *       }
 *   }
 */
abstract class FormRequest extends Request
{
    protected ?Validate $validator = null;

    /** @var array 校验通过的数据 */
    protected array $validatedData = [];

    protected bool $validated = false;

    /**
     * 验证规则，子类重写
     *
     * @return array<string, string|array<string>> 规则数组
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 自定义错误消息，子类重写
     *
     * @return array<string, string> 消息数组
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * 授权检查，子类重写；默认放行
     *
     * @return bool 是否授权
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 执行验证
     *
     * 只校验「请求体」数据（JSON + POST），不包含 GET 查询参数。
     * 此前使用 $this->all()（GET + JSON + POST），导致
     * `POST /users?email=attacker@evil.com` 能用查询串满足 required|email，
     * 使 validated() 返回攻击者可控的 GET 值而非实际提交的数据。
     *
     * @return bool 是否通过
     */
    public function validate(): bool
    {
        $validator = new Validate();
        $validator->rules($this->rules())->messages($this->messages());
        $ok = $validator->validate($this->body());
        $this->validator = $validator;
        $this->validatedData = $ok ? $validator->validated() : [];
        $this->validated = true;
        return $ok;
    }

    /**
     * 获取请求体数据（JSON 优先，回退 POST），不含 GET 查询参数
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->post();
    }

    /**
     * 获取校验通过的数据
     *
     * @return array 校验通过的数据
     */
    public function validated(): array
    {
        if (!$this->validated) {
            $this->validate();
        }
        return $this->validatedData;
    }

    /**
     * 获取校验错误信息
     *
     * @return array 错误信息数组
     */
    public function errors(): array
    {
        if ($this->validator === null) {
            $this->validate();
        }
        return $this->validator->errors();
    }

    /**
     * 由 Router 注入时调用：先授权、后验证，失败抛异常
     *
     * @throws HttpException 当 authorize() 返回 false
     * @throws ValidationException 当 rules() 校验失败
     */
    public function validateResolved(): void
    {
        if (!$this->authorize()) {
            throw new HttpException(403, 'This action is unauthorized.');
        }
        if (!$this->validate()) {
            throw new ValidationException($this->errors());
        }
    }
}
