//go:build linux || darwin

package sqlitevec

/*
#cgo linux LDFLAGS: -ldl -lsqlite3
#cgo darwin LDFLAGS: -lsqlite3
#include <dlfcn.h>
#include <sqlite3.h>
#include <stdlib.h>
#include <string.h>

static char *copy_error(const char *message) {
	if (message == NULL) {
		return NULL;
	}

	size_t length = strlen(message) + 1;
	char *copy = malloc(length);
	if (copy == NULL) {
		return NULL;
	}

	memcpy(copy, message, length);
	return copy;
}

static int register_sqlite_vec_auto_extension(const char *path, char **error) {
	void *handle = dlopen(path, RTLD_NOW | RTLD_LOCAL);
	if (handle == NULL) {
		const char *message = dlerror();
		*error = copy_error(message);
		return 1;
	}

	void *entry = dlsym(handle, "sqlite3_vec_init");
	if (entry == NULL) {
		const char *message = dlerror();
		*error = copy_error(message);
		return 2;
	}

	int rc = sqlite3_auto_extension((void (*)(void)) entry);
	if (rc != SQLITE_OK) {
		*error = copy_error("sqlite3_auto_extension failed");
		return rc;
	}

	return SQLITE_OK;
}
*/
import "C"

import (
	"fmt"
	"unsafe"
)

// register 通过 dlopen 加载 sqlite-vec 动态库并注册为 SQLite 自动扩展（Linux/macOS 实现）。
func register(path string) error {
	cPath := C.CString(path)
	defer C.free(unsafe.Pointer(cPath))

	var cError *C.char
	rc := C.register_sqlite_vec_auto_extension(cPath, &cError)
	if cError != nil {
		defer C.free(unsafe.Pointer(cError))
	}
	if rc != C.SQLITE_OK {
		if cError != nil {
			return fmt.Errorf("%s", C.GoString(cError))
		}

		return fmt.Errorf("sqlite3_auto_extension returned %d", int(rc))
	}

	return nil
}
