import api from './api';

export const adminTokenStorageKey = 'wedding-photo-lottery.admin-token';

export type AdminPhotoReviewStatus = 'normal' | 'good' | 'rejected';

export interface AdminReviewPhoto {
    created_at: string;
    file_size: number;
    height: number | null;
    id: number;
    mime_type: string;
    original_filename: string;
    review_status: 'pending';
    review_url: string;
    uploader_phone_suffix: string;
    width: number | null;
}

interface AdminLoginResponse {
    expires_at: string;
    token: string;
}

interface AdminSessionResponse {
    authenticated: true;
}

interface NextAdminReviewResponse {
    photo: AdminReviewPhoto | null;
    next_photo: AdminReviewPhoto | null;
    remaining_count: number;
}

interface ReviewedPhotoResponse {
    id: number;
    review_status: AdminPhotoReviewStatus | 'pending';
    reviewed_at: string | null;
}

function authorizationHeaders(token: string): { Authorization: string } {
    return { Authorization: `Bearer ${token}` };
}

export async function loginAdmin(password: string): Promise<AdminLoginResponse> {
    const response = await api.post<AdminLoginResponse>('/admin/login', { password });

    return response.data;
}

export async function verifyAdminSession(token: string): Promise<void> {
    await api.get<AdminSessionResponse>('/admin/session', {
        headers: authorizationHeaders(token),
    });
}

export async function fetchNextAdminReview(token: string): Promise<NextAdminReviewResponse> {
    const response = await api.get<NextAdminReviewResponse>('/admin/reviews/next', {
        headers: authorizationHeaders(token),
    });

    return response.data;
}

export async function reviewAdminPhoto(
    token: string,
    photoId: number,
    status: AdminPhotoReviewStatus,
): Promise<ReviewedPhotoResponse> {
    const response = await api.post<ReviewedPhotoResponse>(
        `/admin/photos/${photoId}/review`,
        { status },
        { headers: authorizationHeaders(token) },
    );

    return response.data;
}

export async function undoLatestAdminReview(token: string): Promise<ReviewedPhotoResponse> {
    const response = await api.post<ReviewedPhotoResponse>(
        '/admin/reviews/undo',
        undefined,
        { headers: authorizationHeaders(token) },
    );

    return response.data;
}
