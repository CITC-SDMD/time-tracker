//! One picture of the main screen, shrunk and encoded as a JPEG. The platform part is behind
//! `ScreenCapture` (like `ActivityProvider`), so everything else can be tested with a fake.

use std::fmt;

use image::codecs::jpeg::JpegEncoder;
use image::imageops::FilterType;
use image::{DynamicImage, RgbaImage};

/// The longest side of the stored picture. A 1920x1080 screen becomes 1280x720.
pub const MAX_WIDTH: u32 = 1280;
/// JPEG quality (1-100). About 100-200 KB for a busy 1280 px screen.
pub const JPEG_QUALITY: u8 = 60;

#[derive(Debug)]
pub struct Shot {
    pub jpeg: Vec<u8>,
    pub width: u32,
    pub height: u32,
}

#[derive(Debug)]
pub enum CaptureError {
    /// This platform cannot take screenshots.
    Unsupported,
    /// No screen, a locked desktop, or the OS refused.
    Failed(String),
}

impl fmt::Display for CaptureError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            CaptureError::Unsupported => write!(f, "screenshots are not supported on this platform"),
            CaptureError::Failed(reason) => write!(f, "could not capture the screen: {reason}"),
        }
    }
}

pub trait ScreenCapture: Send + Sync {
    fn capture(&self) -> Result<Shot, CaptureError>;
}

pub fn provider() -> Box<dyn ScreenCapture> {
    Box::new(imp::MainScreen)
}

/// Shrinks `picture` to at most `MAX_WIDTH` wide (never enlarges) and encodes it as a JPEG.
pub fn encode(picture: RgbaImage, max_width: u32, quality: u8) -> Result<Shot, CaptureError> {
    let (width, height) = picture.dimensions();
    if width == 0 || height == 0 {
        return Err(CaptureError::Failed("empty picture".into()));
    }
    let rgb = if width > max_width {
        let new_height = ((height as u64 * max_width as u64) / width as u64).max(1) as u32;
        DynamicImage::ImageRgba8(picture)
            .resize_exact(max_width, new_height, FilterType::Triangle)
            .to_rgb8()
    } else {
        DynamicImage::ImageRgba8(picture).to_rgb8()
    };
    let (width, height) = rgb.dimensions();
    let mut jpeg = Vec::new();
    JpegEncoder::new_with_quality(&mut jpeg, quality)
        .encode_image(&rgb)
        .map_err(|e| CaptureError::Failed(e.to_string()))?;
    Ok(Shot { jpeg, width, height })
}

#[cfg(windows)]
mod imp {
    use super::{encode, CaptureError, ScreenCapture, Shot, JPEG_QUALITY, MAX_WIDTH};

    pub struct MainScreen;

    impl ScreenCapture for MainScreen {
        fn capture(&self) -> Result<Shot, CaptureError> {
            let monitors = xcap::Monitor::all().map_err(|e| CaptureError::Failed(e.to_string()))?;
            let monitor = monitors
                .iter()
                .find(|m| m.is_primary().unwrap_or(false))
                .or_else(|| monitors.first())
                .ok_or_else(|| CaptureError::Failed("no screen found".into()))?;
            let picture = monitor.capture_image().map_err(|e| CaptureError::Failed(e.to_string()))?;
            encode(picture, MAX_WIDTH, JPEG_QUALITY)
        }
    }
}

#[cfg(not(windows))]
mod imp {
    use super::{CaptureError, ScreenCapture, Shot};

    pub struct MainScreen;

    impl ScreenCapture for MainScreen {
        fn capture(&self) -> Result<Shot, CaptureError> {
            Err(CaptureError::Unsupported)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A busy-looking picture (gradients and stripes), so the JPEG size is realistic.
    fn synthetic(width: u32, height: u32) -> RgbaImage {
        RgbaImage::from_fn(width, height, |x, y| {
            let stripe = if (x / 40 + y / 40) % 2 == 0 { 40 } else { 0 };
            image::Rgba([((x * 255 / width) as u8).saturating_add(stripe), ((y * 255 / height) as u8), ((x + y) % 256) as u8, 255])
        })
    }

    #[test]
    fn a_wide_screen_is_shrunk_to_the_maximum_width_keeping_its_shape() {
        let shot = encode(synthetic(1920, 1080), MAX_WIDTH, JPEG_QUALITY).unwrap();
        assert_eq!((shot.width, shot.height), (1280, 720));
        assert_eq!(&shot.jpeg[..3], &[0xFF, 0xD8, 0xFF], "starts with the JPEG marker");
    }

    #[test]
    fn a_small_screen_is_not_enlarged() {
        let shot = encode(synthetic(800, 600), MAX_WIDTH, JPEG_QUALITY).unwrap();
        assert_eq!((shot.width, shot.height), (800, 600));
    }

    #[test]
    fn a_very_wide_picture_keeps_at_least_one_pixel_of_height() {
        let shot = encode(synthetic(5000, 1), MAX_WIDTH, JPEG_QUALITY).unwrap();
        assert_eq!(shot.width, 1280);
        assert_eq!(shot.height, 1);
    }

    #[test]
    fn an_empty_picture_is_an_error() {
        assert!(matches!(encode(RgbaImage::new(0, 0), MAX_WIDTH, JPEG_QUALITY), Err(CaptureError::Failed(_))));
    }

    #[test]
    fn the_jpeg_of_a_full_hd_screen_is_a_sensible_size() {
        let shot = encode(synthetic(1920, 1080), MAX_WIDTH, JPEG_QUALITY).unwrap();
        assert!(shot.jpeg.len() < 1_500_000, "under the server's 1.5 MB limit, was {}", shot.jpeg.len());
    }

    /// The spike (Phase 10 step 0). Run by hand on a Windows PC with a real desktop:
    /// `cargo test --release spike -- --ignored --nocapture`
    #[test]
    #[ignore]
    fn spike_capture_time_and_size_on_this_pc() {
        let capture = provider();
        let mut times = Vec::new();
        let mut sizes = Vec::new();
        let mut dims = (0, 0);
        for _ in 0..20 {
            let started = std::time::Instant::now();
            let shot = capture.capture().expect("capture works");
            times.push(started.elapsed().as_millis());
            sizes.push(shot.jpeg.len());
            dims = (shot.width, shot.height);
            std::thread::sleep(std::time::Duration::from_millis(300));
        }
        times.sort();
        let average = sizes.iter().sum::<usize>() / sizes.len();
        println!("SPIKE size {}x{}  time ms: min {} median {} max {}  jpeg bytes: average {} max {}", dims.0, dims.1, times[0], times[times.len() / 2], times[times.len() - 1], average, sizes.iter().max().unwrap());
    }
}
