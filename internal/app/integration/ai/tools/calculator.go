// tools 包汇集 Go 侧 AI 运行时可用的本地工具集合。
//
// 这里的每个工具都是一个独立的 eino tool.InvokableTool，可以直接挂到 adk.ChatModelAgent
// 的 ToolsConfig.Tools 里。要加入新的本地工具：
//  1. 在本包下添加一个 `xxx.go`，对外暴露 `NewXxxTool()` 或直接导出 struct；
//  2. 在 ai 包里按需把它追加进 `buildDefaultTools()`。
//
// 单独划成子包让 chat_stream.go 保持纤薄，单元测试也可以脱离 agent / mercure
// 直接针对工具函数本身做校验。
package tools

import (
	"context"
	"errors"
	"fmt"
	"math"
	"strings"

	"github.com/cloudwego/eino/components/tool"
	"github.com/cloudwego/eino/components/tool/utils"
)

// 计算器输入是 calculator 工具的输入参数。
//
// 设计上保持克制：四则运算 + 幂运算，两个操作数。复杂场景（表达式解析、多元运算）
// 留给未来单独的表达式求值工具，让 LLM 的 tool schema 保持精炼。
type CalculatorInput struct {
	// 运算类型允许 add / subtract / multiply / divide / power。
	Operation string `json:"operation" jsonschema:"enum=add,enum=subtract,enum=multiply,enum=divide,enum=power,description=Arithmetic operation to perform"`
	// 第一个操作数。
	A float64 `json:"a" jsonschema:"description=Left-hand side operand"`
	// 第二个操作数。
	B float64 `json:"b" jsonschema:"description=Right-hand side operand"`
}

// 计算器输出会被自动 JSON 编码后送回模型。
//
// 保留 Expression 字段的目的是让 LLM 在最终回答里直接引用 “1 + 1 = 2” 这种完整表达式，
// 减少二次拼接时的幻觉。
type CalculatorOutput struct {
	Result     float64 `json:"result"`
	Expression string  `json:"expression"`
}

// calculatorName / calculatorDesc 单独提出来是方便在测试 / 日志里复用。
const (
	calculatorName = "calculator"
	calculatorDesc = "A deterministic calculator for basic arithmetic (add/subtract/multiply/divide/power). " +
		"Prefer calling this tool instead of guessing numeric answers."
)

// errCalculatorDivideByZero 单独列出来便于未来做国际化或按错误类型走不同的用户提示。
var errCalculatorDivideByZero = errors.New("division by zero")

// 构造一个可直接挂到 adk.ChatModelAgent 的计算器工具。
//
// 使用 utils.InferTool：参数 schema 从 CalculatorInput 的 jsonschema tag 推导，
// 结果用标准 JSON encoder 序列化——这两项任一出问题都会在启动阶段直接暴露。
func NewCalculatorTool() (tool.InvokableTool, error) {
	return utils.InferTool(calculatorName, calculatorDesc, invokeCalculator)
}

// 实际执行计算器工具。
//
// 拆成独立函数有两个考虑：
//  1. 单测可以直接喂 CalculatorInput，跳过 JSON 编解码；
//  2. 将来叠加 metrics / 审计时，中间层包装更直接。
func invokeCalculator(_ context.Context, input CalculatorInput) (CalculatorOutput, error) {
	op := strings.ToLower(strings.TrimSpace(input.Operation))

	var (
		result float64
		symbol string
	)

	switch op {
	case "add", "+", "plus":
		result = input.A + input.B
		symbol = "+"
	case "subtract", "sub", "-", "minus":
		result = input.A - input.B
		symbol = "-"
	case "multiply", "mul", "*", "times":
		result = input.A * input.B
		symbol = "*"
	case "divide", "div", "/":
		if input.B == 0 {
			return CalculatorOutput{}, errCalculatorDivideByZero
		}
		result = input.A / input.B
		symbol = "/"
	case "power", "pow", "^", "**":
		result = math.Pow(input.A, input.B)
		symbol = "^"
	default:
		return CalculatorOutput{}, fmt.Errorf("unsupported operation %q: expected one of add/subtract/multiply/divide/power", input.Operation)
	}

	// 结果溢出或变成 NaN 时直接返回错误，让上层把异常算式反馈给模型重新决策。
	if math.IsNaN(result) || math.IsInf(result, 0) {
		return CalculatorOutput{}, fmt.Errorf("calculation overflowed for %v %s %v", input.A, symbol, input.B)
	}

	return CalculatorOutput{
		Result:     result,
		Expression: fmt.Sprintf("%s %s %s = %s", formatNumber(input.A), symbol, formatNumber(input.B), formatNumber(result)),
	}, nil
}

// 把 float 格式化成人类友好的字符串：整数不带小数点，小数最多 6 位。
// LLM 常常会把 `42.000000` 原样复读，提前在工具输出里就清理好。
func formatNumber(v float64) string {
	if v == math.Trunc(v) && math.Abs(v) < 1e15 {
		return fmt.Sprintf("%.0f", v)
	}
	s := fmt.Sprintf("%.6f", v)
	s = strings.TrimRight(s, "0")
	s = strings.TrimRight(s, ".")
	return s
}
