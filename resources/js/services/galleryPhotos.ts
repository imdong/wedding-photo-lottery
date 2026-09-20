import api from './api';

export interface GalleryPhoto {
    created_at: string;
    id: number;
    preview_url: string;
    thumbnail_url: string;
}

export interface GalleryPhotoPage {
    has_more: boolean;
    next_cursor: string | null;
    photos: GalleryPhoto[];
}

export async function fetchGalleryPhotos(cursor: string | null = null): Promise<GalleryPhotoPage> {
    const response = await api.get<GalleryPhotoPage>('/gallery', {
        params: cursor === null ? undefined : { cursor },
    });

    return response.data;
}
