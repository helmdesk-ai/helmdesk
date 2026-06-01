PHP_PREFIX := $(shell php-config --prefix)
WATCHER_PREFIX := $(shell brew --prefix watcher 2>/dev/null)
WATCHER_CFLAGS := $(shell pkg-config --cflags watcher-c 2>/dev/null)
WATCHER_LDFLAGS := $(shell pkg-config --libs watcher-c 2>/dev/null)
LOG_FORMAT ?= text
ifneq ($(WATCHER_PREFIX),)
WATCHER_CFLAGS += -I$(WATCHER_PREFIX)/include
WATCHER_LDFLAGS += -L$(WATCHER_PREFIX)/lib
endif

export CGO_CFLAGS := $(shell php-config --includes) $(WATCHER_CFLAGS)
export CGO_LDFLAGS := -L$(PHP_PREFIX)/lib $(shell php-config --ldflags) $(shell php-config --libs) $(WATCHER_LDFLAGS)
export LOG_FORMAT

.PHONY: run
run:
	go run -mod=mod ./cmd/helmdesk --port 0.0.0.0:8080

.PHONY: test-go
test-go:
	go test -mod=mod ./...

.PHONY: docker-push
docker-push:
	docker buildx bake -f docker-compose.yaml --set "*.platform=linux/amd64,linux/arm64" --push dev

.PHONY: docker-build
docker-build:
	docker buildx bake -f docker-compose.yaml --set "*.platform=linux/arm64" --load dev
