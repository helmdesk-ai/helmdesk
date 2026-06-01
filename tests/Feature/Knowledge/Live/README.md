# Knowledge Live Integration & Recall Benchmarks

本目录下的 Pest 用例需要**真实**调用 Go runtime 与 OpenRouter 上的外部模型，默认全部 `markTestSkipped`，不会进入常规 CI。

## 何时跑这些用例

- 改动了 `app/Services/KnowledgeBase/Search/*`、`app/Actions/KnowledgeBase/SearchKnowledgeBaseAction.php`、`internal/app/integration/knowledge/*`；
- 想拿到一份"真实模型 + 真实分词"下的中文召回基线作为发版前的参考；
- 排查"线下能走，线上失败"的桥接 / 协议兼容问题。

## 一次性配置

参考 `.env.example` 中 `Knowledge live integration / recall benchmark tests` 段，必填四项：

| 变量 | 说明 |
| --- | --- |
| `KNOWLEDGE_RUNTIME_LIVE` | 设为 `1` 才会启用本目录的活体用例 |
| `GO_RUNTIME_BASE_URL` | Go runtime HTTP 地址；本地用 `make` 起完后填上 |
| `HELMDESK_INTERNAL_BRIDGE_TOKEN` | PHP→Go 与 Go→PHP 同一份桥接 token，启动 runtime 时自动注入 |
| `OPENROUTER_API_KEY` | 任意 OpenRouter Key（`sk-or-v1-...`） |

可调（带默认值，全部走 OpenRouter）：

| 变量 | 默认 | 用途 |
| --- | --- | --- |
| `OPENROUTER_LLM_MODEL` | `deepseek/deepseek-v4-flash` | RAPTOR 摘要 / Agent 推理 |
| `OPENROUTER_EMBEDDING_MODEL` | `openai/text-embedding-3-small` | 向量索引、查询向量化 |
| `OPENROUTER_RERANK_MODEL` | `cohere/rerank-4-fast` | 重排模型 ID |

## 跑法

```bash
# 终端 1：起完整本地 runtime（PHP + Go + Vite）
make

# 终端 2：跑活体用例
KNOWLEDGE_RUNTIME_LIVE=1 \
  GO_RUNTIME_BASE_URL=http://127.0.0.1:8080 \
  HELMDESK_INTERNAL_BRIDGE_TOKEN=$(php artisan tinker --execute 'echo config("services.go_runtime.bridge_token");') \
  php artisan test --compact tests/Feature/Knowledge/Live
```

> 如果只想跑某一个文件，附上路径即可：`php artisan test --compact tests/Feature/Knowledge/Live/KnowledgeLiveRecallBenchmarkTest.php`。

## 用例与产物

### `KnowledgeRuntimeIntegrationTest.php`
- canonical text 段 + 真实向量打通：跑完 `WriteCanonicalChunksAction` → `IndexKnowledgeDocumentVectorAction`，断言 `strategy=text` 段有非零 `embedding_dim` 且 `knowledge_vector_tables` 维度被写入；
- RAPTOR 摘要树打通：用真实 LLM 跑出至少一层 `strategy=raptor / kind=summary` 节点。

### `KnowledgeLiveRecallBenchmarkTest.php`（核心）
- 载入 `tests/Fixtures/Knowledge/zh_recall_corpus.json`（57 篇文档 / 40 条查询，含 `lexical / paraphrased / conceptual / disambiguation / multi_positive` 五类标签，结构对齐 DuReader-Retrieval 与 C-MTEB）；
- 在同一份索引上依次切换 workspace 配置，跑三条流水线：**FTS only** / **Hybrid (FTS+Vector)** / **Hybrid + Rerank**；
- 每条流水线打出 `Recall@1/3/5`、`MRR@10`、平均时延、rerank 应用次数、embedding 失败次数；
- 额外输出 **per-query type** 拆解（MRR@10 / R@5），方便定位 vector / rerank 对哪类查询提升最大；
- 最后把每条查询的最强 MRR 与 type 标签输出到 STDERR，便于排查个别中文查询是否回归。

示例输出：

```
[zh-recall-live] embedding=openai/text-embedding-3-small rerank=cohere/rerank-4-fast queries=40 docs=57
pipeline                    R@1      R@3      R@5   MRR@10     avg_ms   rerank embed_err
----------------------------------------------------------------------------------------------
FTS only                 0.7771   0.9229   0.9542   0.9125        3.0        0        0
Hybrid (FTS+Vector)      0.8021   0.9479   0.9542   0.9250      789.7        0        0
Hybrid + Rerank          0.8521   0.9688   0.9875   0.9521     1233.1       40        0
```

### `KnowledgeLiveAgentSearchTest.php`
- 工作区一次性打开 `vector + raptor + rerank`，建两个知识库；
- 通过 `SearchKnowledgeBaseAction` 把 `mode=grep / semantic / hybrid` 各跑一遍，断言每模式的返回结构正确；
- STDERR 输出每模式 latency / hits / rerank_applied / embedding_error，用于排查上线前的"非完美外部依赖"行为。

## Rerank 协议

当前 rerank 仅支持 OpenRouter（参考 `https://openrouter.ai/docs#rerank`）：

```bash
curl https://openrouter.ai/api/v1/rerank \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $OPENROUTER_API_KEY" \
  -d '{"model":"cohere/rerank-4-fast","query":"...","documents":["..."],"top_n":3}'
```

Go 端 `internal/app/integration/knowledge/rerank.go` 按此协议组装请求与解析响应。其它 provider.protocol（含原生 Cohere / Jina）暂未接入，落入 `default` 分支返回 `rerank.model_unavailable`，PHP 侧 `KnowledgeReranker` 自动降级为不重排。

## 与标准测试的边界

- 标准 `tests/Feature/Knowledge/*` 必须保持**离线**可跑（不打外网、不开 token）；新增确定性用例放在那里。
- 一旦用例依赖网络 / API Key / 真实 GPU，请放到本目录，并按现有约定把 `KNOWLEDGE_RUNTIME_LIVE=1` 的 skip 守卫加上。

### 类 grep 评测在哪

类 grep 检索是确定性的 SQL `LIKE` + PHP 字节级扫描，不依赖任何外部模型，因此评测全部留在 `tests/Feature/Knowledge/KnowledgeRecallBenchmarkTest.php`（离线套件）里：

- **`GrepRetriever 在全语料 + 多查询类型下…`**：用 40 条自然查询过一遍，按 type 打表统计 `oracle_should_hit / literal_recall / total_hits / fp_docs`，并校验每条 `grep_match` 的 `byte_start..byte_end` 能精确切回 query 自身；
- **`GrepRetriever 在随机抽取的中文短语上…`**：从语料里随机挖出 4-8 字的纯中文短语（~160+ 条），断言 grep 100% 召回源文档且位置精度无误；
- **`GrepRetriever 在多 query 数组输入下…`**：验证 `query` 数组输入下多个字面短语的命中能正确合并。
