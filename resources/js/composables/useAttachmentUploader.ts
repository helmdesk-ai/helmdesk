/**
 * 文件说明：附件上传组合式函数，封装创建上传意图、代理上传、对象存储直传和分片完成流程。
 */
import attachmentUploads from '@/routes/attachments/uploads';
import visitorAttachmentUploads from '@/routes/visitor/attachments/uploads';
import type {
  AttachmentPurpose,
  AttachmentUploadIntentData,
  UploadedAttachmentData,
} from '@/types/generated';
import axios from 'axios';

export type { AttachmentPurpose, UploadedAttachmentData };

type CreateUploadResponse = AttachmentUploadIntentData;

interface SignedPartsResponse {
  parts: Array<{
    part_number: number;
    url: string;
    method: 'PUT';
  }>;
}

export interface AttachmentUploadOptions {
  purpose: AttachmentPurpose;
  scope?: 'authenticated' | 'visitor';
  context?: Record<string, unknown>;
  // 访客会话 token：visitor 作用域下作为 X-Helmdesk-Visitor-Token 发往 HelmDesk 上传端点。
  visitorToken?: string;
  onProgress?: (progress: number) => void;
  signal?: AbortSignal;
}

const MULTIPART_CONCURRENCY = 3;

type UploadErrorCode =
  | 'missing_presigned_post'
  | 'missing_presigned_url'
  | 'missing_part_size';

type Translate = (
  key: string,
  params?: Record<string, string | number>,
) => string;

const UPLOAD_ERROR_MESSAGE_KEYS: Record<UploadErrorCode, string> = {
  missing_presigned_post: '缺少直传表单参数。',
  missing_presigned_url: '缺少直传地址。',
  missing_part_size: '缺少分片大小。',
};

class AttachmentUploadError extends Error {
  constructor(public readonly code: UploadErrorCode) {
    super(code);
    this.name = 'AttachmentUploadError';
  }
}

export function resolveAttachmentUploadError(
  error: unknown,
  t: Translate,
  fallbackKey = '上传失败，请重试',
): string {
  if (error instanceof AttachmentUploadError) {
    return t(UPLOAD_ERROR_MESSAGE_KEYS[error.code]);
  }

  if (axios.isAxiosError(error)) {
    const message = error.response?.data?.message;
    if (typeof message === 'string' && message !== '') {
      return message;
    }
  }

  if (error instanceof Error && error.message) {
    return error.message;
  }

  return t(fallbackKey);
}

export function useAttachmentUploader() {
  async function upload(
    file: File,
    options: AttachmentUploadOptions,
  ): Promise<UploadedAttachmentData> {
    options.onProgress?.(0);

    const created = await createUpload(file, options);

    if (created.upload.mode === 'proxy') {
      await uploadProxy(created.upload.id, file, options);
    } else if (created.upload.mode === 'presigned_post') {
      await uploadPresignedPost(created, file, options);
    } else if (created.upload.mode === 'presigned_put') {
      await uploadPresignedPut(created, file, options);
    } else {
      return uploadMultipart(created, file, options);
    }

    const routes = uploadRoutes(options);
    const completed = await axios.post<{ attachment: UploadedAttachmentData }>(
      routes.complete.url(created.upload.id),
      {},
      {
        headers: visitorTokenHeaders(options),
        signal: options.signal,
      },
    );

    options.onProgress?.(100);

    return completed.data.attachment;
  }

  async function createUpload(
    file: File,
    options: AttachmentUploadOptions,
  ): Promise<CreateUploadResponse> {
    const routes = uploadRoutes(options);
    const response = await axios.post<AttachmentUploadIntentData>(
      routes.create.url(),
      {
        purpose: options.purpose,
        file_name: file.name,
        mime_type: file.type || 'application/octet-stream',
        byte_size: file.size,
        context: options.context ?? {},
      },
      {
        headers: visitorTokenHeaders(options),
        signal: options.signal,
      },
    );

    return response.data;
  }

  async function uploadProxy(
    uploadId: string,
    file: File,
    options: AttachmentUploadOptions,
  ): Promise<void> {
    const formData = new FormData();
    formData.append('file', file);

    const routes = uploadRoutes(options);
    await axios.post(routes.proxy.url(uploadId), formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
        ...visitorTokenHeaders(options),
      },
      signal: options.signal,
      onUploadProgress: (event) => {
        if (!event.total) return;
        options.onProgress?.(Math.round((event.loaded / event.total) * 95));
      },
    });
  }

  async function uploadPresignedPost(
    created: CreateUploadResponse,
    file: File,
    options: AttachmentUploadOptions,
  ): Promise<void> {
    if (!created.direct?.url || !created.direct.fields) {
      throw new AttachmentUploadError('missing_presigned_post');
    }

    const formData = new FormData();
    Object.entries(created.direct.fields).forEach(([key, value]) => {
      formData.append(key, value);
    });
    formData.append('file', file);

    await axios.post(created.direct.url, formData, {
      withCredentials: false,
      signal: options.signal,
      onUploadProgress: (event) => {
        if (!event.total) return;
        options.onProgress?.(Math.round((event.loaded / event.total) * 95));
      },
    });
  }

  async function uploadPresignedPut(
    created: CreateUploadResponse,
    file: File,
    options: AttachmentUploadOptions,
  ): Promise<void> {
    if (!created.direct?.url) {
      throw new AttachmentUploadError('missing_presigned_url');
    }

    await axios.put(created.direct.url, file, {
      headers: created.direct.headers ?? { 'Content-Type': file.type },
      withCredentials: false,
      signal: options.signal,
      onUploadProgress: (event) => {
        if (!event.total) return;
        options.onProgress?.(Math.round((event.loaded / event.total) * 95));
      },
    });
  }

  async function uploadMultipart(
    created: CreateUploadResponse,
    file: File,
    options: AttachmentUploadOptions,
  ): Promise<UploadedAttachmentData> {
    const partSize = created.upload.part_size ?? created.direct?.part_size;
    if (!partSize) {
      throw new AttachmentUploadError('missing_part_size');
    }

    const chunks = splitFile(file, partSize);
    const uploadedBytes = new Map<number, number>();
    const completedParts: Array<{ part_number: number; etag: string }> = [];
    let cursor = 0;

    async function worker(): Promise<void> {
      while (cursor < chunks.length) {
        const index = cursor;
        cursor += 1;
        const chunk = chunks[index];
        const signed = await signParts(
          created.upload.id,
          [chunk.partNumber],
          options,
        );
        const target = signed.parts[0];

        const response = await axios.put(target.url, chunk.blob, {
          withCredentials: false,
          signal: options.signal,
          onUploadProgress: (event) => {
            uploadedBytes.set(
              chunk.partNumber,
              Math.min(event.loaded, chunk.blob.size),
            );
            const uploaded = [...uploadedBytes.values()].reduce(
              (total, value) => total + value,
              0,
            );
            options.onProgress?.(Math.round((uploaded / file.size) * 95));
          },
        });

        completedParts.push({
          part_number: chunk.partNumber,
          etag: String(response.headers.etag ?? '').replace(/^"|"$/g, ''),
        });
      }
    }

    await Promise.all(
      Array.from(
        { length: Math.min(MULTIPART_CONCURRENCY, chunks.length) },
        () => worker(),
      ),
    );

    const routes = uploadRoutes(options);
    const completed = await axios.post<{ attachment: UploadedAttachmentData }>(
      routes.complete.url(created.upload.id),
      {
        parts: completedParts.sort((a, b) => a.part_number - b.part_number),
      },
      {
        headers: visitorTokenHeaders(options),
        signal: options.signal,
      },
    );

    options.onProgress?.(100);

    return completed.data.attachment;
  }

  async function signParts(
    uploadId: string,
    parts: number[],
    options: AttachmentUploadOptions,
  ): Promise<SignedPartsResponse> {
    const routes = uploadRoutes(options);
    const response = await axios.post<SignedPartsResponse>(
      routes.parts.url(uploadId),
      { parts },
      {
        headers: visitorTokenHeaders(options),
        signal: options.signal,
      },
    );

    return response.data;
  }

  return { upload };
}

function uploadRoutes(options: AttachmentUploadOptions) {
  return options.scope === 'visitor'
    ? visitorAttachmentUploads
    : attachmentUploads;
}

function visitorTokenHeaders(
  options: AttachmentUploadOptions,
): Record<string, string> {
  const token = options.visitorToken;

  return options.scope === 'visitor' &&
    typeof token === 'string' &&
    token !== ''
    ? { 'X-Helmdesk-Visitor-Token': token }
    : {};
}

function splitFile(
  file: File,
  partSize: number,
): Array<{
  partNumber: number;
  blob: Blob;
}> {
  const chunks: Array<{ partNumber: number; blob: Blob }> = [];

  for (
    let offset = 0, partNumber = 1;
    offset < file.size;
    offset += partSize, partNumber += 1
  ) {
    chunks.push({
      partNumber,
      blob: file.slice(offset, Math.min(offset + partSize, file.size)),
    });
  }

  return chunks;
}
