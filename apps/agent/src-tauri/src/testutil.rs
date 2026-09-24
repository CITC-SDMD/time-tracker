//! Test-only: a one-connection-per-response HTTP server on localhost that replays canned
//! responses in order and records the raw requests it received.

use std::sync::{Arc, Mutex};

use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpListener;

pub struct MockServer {
    pub root: String,
    pub requests: Arc<Mutex<Vec<String>>>,
}

impl MockServer {
    /// `responses` are `(status, json_body)`, served one per incoming request, in order.
    pub async fn start(responses: Vec<(u16, String)>) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let root = format!("http://{}", listener.local_addr().unwrap());
        let requests = Arc::new(Mutex::new(Vec::new()));
        let recorded = requests.clone();

        tokio::spawn(async move {
            for (status, body) in responses {
                let Ok((mut socket, _)) = listener.accept().await else { return };
                let raw = read_request(&mut socket).await;
                recorded.lock().unwrap().push(raw);
                let reply = format!(
                    "HTTP/1.1 {status} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}",
                    body.len()
                );
                let _ = socket.write_all(reply.as_bytes()).await;
                let _ = socket.shutdown().await;
            }
        });

        Self { root, requests }
    }

    pub fn request(&self, index: usize) -> String {
        self.requests.lock().unwrap()[index].clone()
    }

    pub fn request_count(&self) -> usize {
        self.requests.lock().unwrap().len()
    }
}

/// A root URL nothing is listening on, for "offline" tests.
pub async fn dead_root() -> String {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let root = format!("http://{}", listener.local_addr().unwrap());
    drop(listener);
    root
}

async fn read_request(socket: &mut tokio::net::TcpStream) -> String {
    let mut buf = Vec::new();
    let mut chunk = [0u8; 4096];
    loop {
        let n = socket.read(&mut chunk).await.unwrap_or(0);
        if n == 0 {
            break;
        }
        buf.extend_from_slice(&chunk[..n]);
        if let Some(head_end) = find(&buf, b"\r\n\r\n") {
            let head = String::from_utf8_lossy(&buf[..head_end]).to_lowercase();
            let content_length = head
                .lines()
                .find_map(|l| l.strip_prefix("content-length:"))
                .and_then(|v| v.trim().parse::<usize>().ok())
                .unwrap_or(0);
            if buf.len() >= head_end + 4 + content_length {
                break;
            }
        }
    }
    String::from_utf8_lossy(&buf).into_owned()
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    haystack.windows(needle.len()).position(|w| w == needle)
}
