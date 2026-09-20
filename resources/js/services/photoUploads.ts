import api from './api';

export interface PhotoPreparationInput {
    file_size: number;
    filename: string;
    mime_type: string;
    original_file_hash: string;
    upload_file_hash: string;
}

export interface OssTemporaryCredentials {
    access_key_id: string;
    access_key_secret: string;
    bucket: string;
    bucket_url: string;
    endpoint: string;
    expiration: string;
    object_key_prefix: string;
    public_url: string;
    region: string;
    security_token: string;
}

interface ReadyPhotoPreparation {
    index: number;
    object_key: string;
    original_file_hash: string;
    photo_id: number;
    status: 'ready';
    upload_file_hash: string;
}

interface DuplicatePhotoPreparation {
    duplicate_hashes: string[];
    index: number;
    status: 'duplicate';
}

export interface PhotoPreparationResponse {
    credentials: OssTemporaryCredentials;
    photos: Array<ReadyPhotoPreparation | DuplicatePhotoPreparation>;
}

export async function preparePhotoUploads(photos: PhotoPreparationInput[]): Promise<PhotoPreparationResponse> {
    const response = await api.post<PhotoPreparationResponse>('/uploads/prepare', { photos }, { timeout: 10_000 });

    return response.data;
}
