import type { OssTemporaryCredentials } from './photoUploads';

type ProgressCallback = (percentage: number) => void;

export async function uploadPhotoToOss(
    file: Blob,
    objectKey: string,
    credentials: OssTemporaryCredentials,
    onProgress: ProgressCallback,
): Promise<void> {
    const date = new Date().toUTCString();
    const authorization = await createOssAuthorization(credentials, objectKey, file.type, date);
    const objectUrl = `${credentials.bucket_url}/${encodeObjectKey(objectKey)}`;

    await new Promise<void>((resolve, reject) => {
        const request = new XMLHttpRequest();

        request.open('PUT', objectUrl);
        request.timeout = 120_000;
        request.setRequestHeader('Authorization', authorization);
        request.setRequestHeader('Content-Type', file.type);
        request.setRequestHeader('x-oss-date', date);
        request.setRequestHeader('x-oss-security-token', credentials.security_token);
        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(Math.min(100, Math.round((event.loaded / event.total) * 100)));
            }
        });
        request.addEventListener('load', () => {
            if (request.status >= 200 && request.status < 300) {
                onProgress(100);
                resolve();

                return;
            }

            reject(new Error('OSS upload failed.'));
        });
        request.addEventListener('error', () => reject(new Error('OSS upload failed.')));
        request.addEventListener('timeout', () => reject(new Error('OSS upload timed out.')));
        request.send(file);
    });
}

export async function createOssAuthorization(
    credentials: OssTemporaryCredentials,
    objectKey: string,
    contentType: string,
    date: string,
): Promise<string> {
    const canonicalHeaders = `x-oss-date:${date}\nx-oss-security-token:${credentials.security_token}\n`;
    const canonicalResource = `/${credentials.bucket}/${objectKey}`;
    const stringToSign = `PUT\n\n${contentType}\n${date}\n${canonicalHeaders}${canonicalResource}`;
    const signature = await hmacSha1Base64(credentials.access_key_secret, stringToSign);

    return `OSS ${credentials.access_key_id}:${signature}`;
}

export function calculateHmacSha1(key: Uint8Array, message: Uint8Array): Uint8Array {
    const blockSize = 64;
    const normalizedKey = key.length > blockSize ? calculateSha1(key) : key;
    const keyBlock = new Uint8Array(blockSize);
    const innerPadding = new Uint8Array(blockSize);
    const outerPadding = new Uint8Array(blockSize);

    keyBlock.set(normalizedKey);

    for (let index = 0; index < blockSize; index += 1) {
        innerPadding[index] = keyBlock[index] ^ 0x36;
        outerPadding[index] = keyBlock[index] ^ 0x5c;
    }

    const innerHash = calculateSha1(concatenate(innerPadding, message));

    return calculateSha1(concatenate(outerPadding, innerHash));
}

async function hmacSha1Base64(secret: string, message: string): Promise<string> {
    const encoder = new TextEncoder();
    const keyBytes = encoder.encode(secret);
    const messageBytes = encoder.encode(message);

    if (globalThis.crypto?.subtle !== undefined) {
        const key = await globalThis.crypto.subtle.importKey(
            'raw',
            keyBytes,
            { hash: 'SHA-1', name: 'HMAC' },
            false,
            ['sign'],
        );
        const signature = await globalThis.crypto.subtle.sign('HMAC', key, messageBytes);

        return bytesToBase64(new Uint8Array(signature));
    }

    return bytesToBase64(calculateHmacSha1(keyBytes, messageBytes));
}

function calculateSha1(bytes: Uint8Array): Uint8Array {
    const bitLength = bytes.length * 8;
    const paddedLength = Math.ceil((bytes.length + 9) / 64) * 64;
    const padded = new Uint8Array(paddedLength);
    const view = new DataView(padded.buffer);
    const words = new Uint32Array(80);
    let first = 0x67452301;
    let second = 0xefcdab89;
    let third = 0x98badcfe;
    let fourth = 0x10325476;
    let fifth = 0xc3d2e1f0;

    padded.set(bytes);
    padded[bytes.length] = 0x80;
    view.setUint32(paddedLength - 8, Math.floor(bitLength / 0x1_0000_0000), false);
    view.setUint32(paddedLength - 4, bitLength >>> 0, false);

    for (let offset = 0; offset < paddedLength; offset += 64) {
        for (let index = 0; index < 16; index += 1) {
            words[index] = view.getUint32(offset + index * 4, false);
        }

        for (let index = 16; index < 80; index += 1) {
            words[index] = rotateLeft(words[index - 3] ^ words[index - 8] ^ words[index - 14] ^ words[index - 16], 1);
        }

        let a = first;
        let b = second;
        let c = third;
        let d = fourth;
        let e = fifth;

        for (let index = 0; index < 80; index += 1) {
            let choice: number;
            let constant: number;

            if (index < 20) {
                choice = (b & c) | (~b & d);
                constant = 0x5a827999;
            } else if (index < 40) {
                choice = b ^ c ^ d;
                constant = 0x6ed9eba1;
            } else if (index < 60) {
                choice = (b & c) | (b & d) | (c & d);
                constant = 0x8f1bbcdc;
            } else {
                choice = b ^ c ^ d;
                constant = 0xca62c1d6;
            }

            const temporary = (rotateLeft(a, 5) + choice + e + constant + words[index]) >>> 0;

            e = d;
            d = c;
            c = rotateLeft(b, 30);
            b = a;
            a = temporary;
        }

        first = (first + a) >>> 0;
        second = (second + b) >>> 0;
        third = (third + c) >>> 0;
        fourth = (fourth + d) >>> 0;
        fifth = (fifth + e) >>> 0;
    }

    const digest = new Uint8Array(20);
    const digestView = new DataView(digest.buffer);

    [first, second, third, fourth, fifth].forEach((value, index) => {
        digestView.setUint32(index * 4, value, false);
    });

    return digest;
}

function rotateLeft(value: number, amount: number): number {
    return ((value << amount) | (value >>> (32 - amount))) >>> 0;
}

function concatenate(first: Uint8Array, second: Uint8Array): Uint8Array {
    const combined = new Uint8Array(first.length + second.length);

    combined.set(first);
    combined.set(second, first.length);

    return combined;
}

function bytesToBase64(bytes: Uint8Array): string {
    return btoa(String.fromCharCode(...bytes));
}

function encodeObjectKey(objectKey: string): string {
    return objectKey.split('/').map(encodeURIComponent).join('/');
}
