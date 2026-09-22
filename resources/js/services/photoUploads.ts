import api from './api';

export interface PhotoPreparationInput {
    filename: string;
    original_file_size: number;
    original_file_hash: string;
    original_mime_type: string;
    upload_file_size: number;
    upload_file_hash: string;
    upload_mime_type: string;
}

export interface OssTemporaryCredentials {
    access_key_id: string;
    access_key_secret: string;
    bucket: string;
    expiration: string;
    security_token: string;
    upload_url: string;
}

interface ReadyPhotoPreparation {
    index: number;
    object_key: string;
    original_object_key: string;
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

interface CompletedPhotoUpload {
    id: number;
    review_status: 'pending';
    upload_status: 'uploaded';
}

export interface CurrentUserPhoto {
    created_at: string;
    file_size: number;
    id: number;
    mime_type: string;
    original_filename: string;
    review_status: 'pending' | 'normal' | 'good' | 'rejected';
    thumbnail_url?: string;
    upload_status: 'preparing' | 'uploaded' | 'failed';
}

export interface CurrentUserPhotoStats {
    approved_count: number;
    pending_count: number;
    rejected_count: number;
    uploaded_count: number;
}

interface CurrentUserPhotoResponse {
    photos: CurrentUserPhoto[];
}

export async function preparePhotoUploads(photos: PhotoPreparationInput[]): Promise<PhotoPreparationResponse> {
    const response = await api.post<PhotoPreparationResponse>('/uploads/prepare', { photos }, { timeout: 10_000 });

    return response.data;
}

export async function completePhotoUpload(photoId: number): Promise<CompletedPhotoUpload> {
    const response = await api.post<CompletedPhotoUpload>(`/uploads/${photoId}/complete`, undefined, { timeout: 20_000 });

    return response.data;
}

export async function fetchCurrentUserPhotos(): Promise<CurrentUserPhoto[]> {
    const response = await api.get<CurrentUserPhotoResponse>('/photos/mine');

    return response.data.photos;
}

export async function fetchCurrentUserPhotoStats(): Promise<CurrentUserPhotoStats> {
    const response = await api.get<CurrentUserPhotoStats>('/me/stats');

    return response.data;
}
