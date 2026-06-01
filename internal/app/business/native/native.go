package native

import (
	"encoding/json"
	"helmdesk/internal/app/config"
	"helmdesk/internal/app/phpbridge"
	"log"
	"net/http"
)

// 示例处理器展示 Native bridge 调用方式。
// phpbridge.CallNative 只能调用 App\Actions\Native\ 下的 Bridge Action。
func ExampleHandler(cfg *config.Config) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		result, err := phpbridge.CallNative(
			cfg.NativeWorkers,
			`App\Actions\Native\Channel\Web\ResolvePublicWebChannelBootstrapBridgeAction`,
			"wch_example",
		)
		if err != nil {
			if bridgeErr := phpbridge.AsBridgeError(err); bridgeErr != nil && bridgeErr.IsClientError() {
				http.Error(w, bridgeErr.Message, bridgeErr.StatusCode)
				return
			}
			log.Printf("Native 调用失败: %v", err)
			http.Error(w, "internal error", http.StatusInternalServerError)
			return
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(result)
	}
}
