import api from './api';

export interface GalleryPhoto {
    can_like: boolean;
    created_at: string;
    id: number;
    is_mine: boolean;
    like_count: number;
    liked_by_me: boolean;
    preview_url: string;
    thumbnail_url: string;
}

export interface GalleryPhotoPage {
    has_more: boolean;
    next_cursor: string | null;
    photos: GalleryPhoto[];
    total_count: number;
}

export async function fetchGalleryPhotos(cursor: string | null = null): Promise<GalleryPhotoPage> {
    const response = await api.get<GalleryPhotoPage>('/gallery', {
        params: cursor === null ? undefined : { cursor },
    });

    return response.data;
}

interface PhotoLikeResponse {
    liked: boolean;
    photo_id: number;
}

export async function updatePhotoLike(photoId: number, liked: boolean): Promise<PhotoLikeResponse> {
    const response = liked
        ? await api.post<PhotoLikeResponse>(`/photos/${photoId}/like`)
        : await api.delete<PhotoLikeResponse>(`/photos/${photoId}/like`);

    return response.data;
}
